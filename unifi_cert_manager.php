#!/usr/bin/php
<?php
/**
 * Unifi UDM SE Certificate Manager
 *
 * This script obtains/renews a Let's Encrypt certificate and uploads it
 * to a Unifi UDM SE device via the Access API.
 *
 * Requirements:
 * - PHP 7.4+ with curl extension
 * - certbot installed (sudo amazon-linux-extras install epel && sudo yum install certbot)
 * - Root/sudo access for certbot
 *
 * Usage:
 * - Run manually: sudo php unifi_cert_manager.php
 * - Or via cron: 0 3 * * * root /usr/bin/php /path/to/unifi_cert_manager.php >> /var/log/unifi_cert.log 2>&1
 */

// ============================================================================
// CONFIGURATION - Update these values
// ============================================================================

$config = [
    // Domain for the certificate
    'domain' => 'unifi.samespace.work',

    // Unifi UDM SE settings
    // Supports environment variables: set UNIFI_API_TOKEN instead of hardcoding
    'unifi_host' => 'https://unifi.samespace.work:12445',
    'unifi_api_token' => getenv('UNIFI_API_KEY') ?: 'YOUR_API_TOKEN_HERE',

    // SSH settings for restarting Access service (optional)
    // Set to false if you prefer to restart manually or if SSH auth isn't working
    'ssh_enabled' => true,
    'ssh_host' => 'unifi.samespace.work',  // Same as unifi_host but without https:// and port
    'ssh_port' => 22,
    'ssh_user' => 'ui',
    'ssh_key_file' => '/root/.ssh/id_ed25519',  // Path to SSH private key
    'ssh_password' => getenv('UDM_SSH_PASSWORD') ?: '',  // Or use env var (less secure than key)
    'ssh_restart_command' => 'systemctl restart unifi-access',  // Command to restart Access service

    // Let's Encrypt settings
    'email' => 'admin@samespace.work',  // For Let's Encrypt notifications
    'cert_path' => '/etc/letsencrypt/live/unifi.samespace.work',

    // Validation method: 'http' (requires port 80) or 'dns' (uses Route 53, no open ports)
    'validation_method' => 'dns',

    // For DNS validation with Route 53 - set your hosted zone ID (optional, auto-detected if omitted)
    // Find it in Route 53 console or run: aws route53 list-hosted-zones
    'route53_hosted_zone_id' => 'Z0503688E78THPR9ZJKC',

    // Optional: Set to true for initial testing with Let's Encrypt staging
    'staging' => false,

    // Days before expiry to renew
    'renew_days' => 30,

    // Log file
    'log_file' => '/var/log/unifi_cert_manager.log',
];

// ============================================================================
// MAIN SCRIPT
// ============================================================================

class UnifiCertManager
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Main entry point
     */
    public function run(): int
    {
        $this->log("=== Starting certificate management process ===");

        try {
            // Step 1: Check if certificate needs renewal or doesn't exist
            if ($this->needsCertificate()) {
                $this->log("Certificate needs to be obtained/renewed");

                // Step 2: Obtain/renew the certificate
                if (!$this->obtainCertificate()) {
                    throw new Exception("Failed to obtain certificate");
                }

                $this->log("Certificate obtained successfully");
            } else {
                $this->log("Certificate is still valid, checking if upload is needed");
            }

            // Step 3: Check if the UDM SE already has this certificate
            if ($this->remoteCertMatchesLocal()) {
                $this->log("Remote certificate already matches local certificate - nothing to do");
                $this->log("=== Process completed successfully (no changes needed) ===");
                return 0;
            }

            // Step 4: Upload certificate to Unifi
            if (!$this->uploadCertificate()) {
                throw new Exception("Failed to upload certificate to Unifi");
            }

            $this->log("Certificate uploaded successfully to Unifi UDM SE");

            // Step 5: Restart the Access service if SSH is enabled
            if (!empty($this->config['ssh_enabled'])) {
                if (!$this->restartAccessService()) {
                    $this->log("WARNING: Failed to restart Access service - manual restart required");
                } else {
                    $this->log("Access service restarted successfully");
                }
            } else {
                $this->log("Note: SSH disabled - restart the Access application manually to apply changes");
            }

            $this->log("=== Process completed successfully ===");

            return 0;

        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Check if certificate needs to be obtained or renewed
     */
    private function needsCertificate(): bool
    {
        $certFile = $this->config['cert_path'] . '/fullchain.pem';

        if (!file_exists($certFile)) {
            $this->log("Certificate file does not exist");
            return true;
        }

        // Check expiration
        $certData = file_get_contents($certFile);
        $cert = openssl_x509_parse($certData);

        if (!$cert) {
            $this->log("Could not parse certificate");
            return true;
        }

        $expiryTime = $cert['validTo_time_t'];
        $daysUntilExpiry = ($expiryTime - time()) / 86400;

        $this->log("Certificate expires in " . round($daysUntilExpiry) . " days");

        return $daysUntilExpiry < $this->config['renew_days'];
    }

    /**
     * Check if the certificate currently served by the UDM SE matches the local cert
     */
    private function remoteCertMatchesLocal(): bool
    {
        $certFile = $this->config['cert_path'] . '/fullchain.pem';

        if (!file_exists($certFile)) {
            return false;
        }

        // Get fingerprint of local certificate
        $localCert = file_get_contents($certFile);
        $localParsed = openssl_x509_parse($localCert);
        if (!$localParsed) {
            $this->log("Could not parse local certificate");
            return false;
        }
        $localFingerprint = openssl_x509_fingerprint($localCert, 'sha256');

        // Get the certificate currently served by the UDM SE
        $host = $this->config['ssh_host'];
        $port = 12445; // Access API port
        $ctx = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if (!$client) {
            $this->log("Could not connect to {$host}:{$port} to check remote cert: {$errstr}");
            return false;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        if (!isset($params['options']['ssl']['peer_certificate'])) {
            $this->log("Could not retrieve remote certificate");
            return false;
        }

        $remoteCertResource = $params['options']['ssl']['peer_certificate'];
        openssl_x509_export($remoteCertResource, $remoteCertPem);
        $remoteFingerprint = openssl_x509_fingerprint($remoteCertPem, 'sha256');

        $this->log("Local cert fingerprint:  {$localFingerprint}");
        $this->log("Remote cert fingerprint: {$remoteFingerprint}");

        return $localFingerprint === $remoteFingerprint;
    }

    /**
     * Obtain or renew certificate using certbot
     */
    private function obtainCertificate(): bool
    {
        $domain = escapeshellarg($this->config['domain']);
        $email = escapeshellarg($this->config['email']);
        $validationMethod = $this->config['validation_method'] ?? 'http';

        // Build certbot command based on validation method
        if ($validationMethod === 'dns') {
            $cmd = "/home/admin/.local/bin/certbot certonly --dns-route53";
            $this->log("Using DNS-01 validation via Route 53 (no open ports required)");
        } else {
            $cmd = "/home/admin/.local/bin/certbot certonly --standalone";
            $this->log("Using HTTP-01 validation (requires port 80)");
        }

        $cmd .= " -d {$domain}";
        $cmd .= " --email {$email}";
        $cmd .= " --agree-tos";
        $cmd .= " --non-interactive";
        $cmd .= " --keep-until-expiring";

        if ($this->config['staging']) {
            $cmd .= " --staging";
            $this->log("Using Let's Encrypt STAGING environment");
        }

        $this->log("Running certbot: {$cmd}");

        $output = [];
        $returnCode = 0;
        exec($cmd . " 2>&1", $output, $returnCode);

        $this->log("Certbot output: " . implode("\n", $output));

        return $returnCode === 0;
    }

    /**
     * Upload certificate to Unifi UDM SE
     */
    private function uploadCertificate(): bool
    {
        $keyFile = $this->config['cert_path'] . '/privkey.pem';
        $certFile = $this->config['cert_path'] . '/fullchain.pem';

        // Verify files exist
        if (!file_exists($keyFile)) {
            throw new Exception("Private key file not found: {$keyFile}");
        }
        if (!file_exists($certFile)) {
            throw new Exception("Certificate file not found: {$certFile}");
        }

        $this->log("Uploading certificate to Unifi UDM SE");

        // Build the API URL
        $url = rtrim($this->config['unifi_host'], '/') . '/api/v1/developer/api_server/certificates';

        // Prepare the multipart form data
        $ch = curl_init($url);

        $postFields = [
            'key' => new CURLFile($keyFile, 'application/x-pem-file', 'server.key'),
            'cert' => new CURLFile($certFile, 'application/x-pem-file', 'server.crt'),
        ];

        // Set SSL options first to disable verification (for self-signed/staging certs)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        // Set other options
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->config['unifi_api_token'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL error: {$error}");
        }

        $this->log("API Response (HTTP {$httpCode}): {$response}");

        // Parse response
        $responseData = json_decode($response, true);

        if ($httpCode !== 200) {
            throw new Exception("API returned HTTP {$httpCode}: {$response}");
        }

        if (!isset($responseData['code']) || $responseData['code'] !== 'SUCCESS') {
            throw new Exception("API error: " . ($responseData['msg'] ?? 'Unknown error'));
        }

        return true;
    }

    /**
     * Restart the Access service on UDM SE via SSH
     */
    private function restartAccessService(): bool
    {
        $host = $this->config['ssh_host'];
        $port = $this->config['ssh_port'] ?? 22;
        $user = $this->config['ssh_user'] ?? 'root';
        $keyFile = $this->config['ssh_key_file'] ?? '';
        $password = $this->config['ssh_password'] ?? '';

        // The command to restart the Access service on UDM SE
        $restartCommand = $this->config['ssh_restart_command'] ?? 'systemctl restart unifi-access';

        $this->log("Attempting to restart Access service via SSH");

        // Try using ssh command (works without php-ssh2 extension)
        return $this->sshExecCommand($host, $port, $user, $keyFile, $password, $restartCommand);
    }

    /**
     * Execute command via SSH using ssh command-line tool
     */
    private function sshExecCommand(
        string $host,
        int $port,
        string $user,
        string $keyFile,
        string $password,
        string $command
    ): bool {
        // If we have a password, use expect for keyboard-interactive auth (required by UniFi)
        if (!empty($password) && (empty($keyFile) || !file_exists($keyFile))) {
            return $this->sshExecWithExpect($host, $port, $user, $password, $command);
        }

        // Build SSH command for key-based auth
        $sshCmd = 'ssh';
        $sshCmd .= ' -o StrictHostKeyChecking=no';
        $sshCmd .= ' -o UserKnownHostsFile=/dev/null';
        $sshCmd .= ' -o ConnectTimeout=10';
        $sshCmd .= ' -p ' . escapeshellarg((string)$port);

        if (!empty($keyFile) && file_exists($keyFile)) {
            $sshCmd .= ' -i ' . escapeshellarg($keyFile);
        }

        $sshCmd .= ' ' . escapeshellarg("{$user}@{$host}");
        $sshCmd .= ' ' . escapeshellarg($command);

        $this->log("Executing SSH command to restart service");

        $output = [];
        $returnCode = 0;
        exec($sshCmd . " 2>&1", $output, $returnCode);

        $outputStr = implode("\n", $output);
        if (!empty($outputStr)) {
            $this->log("SSH output: {$outputStr}");
        }

        if ($returnCode !== 0) {
            $this->log("SSH command failed with exit code: {$returnCode}");
            return false;
        }

        // Give the service time to restart
        $this->log("Waiting 10 seconds for service to restart...");
        sleep(10);

        return true;
    }

    /**
     * Execute SSH command using expect for keyboard-interactive authentication
     */
    private function sshExecWithExpect(
        string $host,
        int $port,
        string $user,
        string $password,
        string $command
    ): bool {
        if (!$this->commandExists('expect')) {
            $this->log("ERROR: expect not installed. Install with: sudo apt-get install expect");
            return false;
        }

        $this->log("Executing SSH command via expect (keyboard-interactive auth)");

        // Build expect script
        $expectScript = <<<EXPECT
#!/usr/bin/expect -f
set timeout 30
spawn ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -p {$port} {$user}@{$host} {$command}
expect {
    "password:" {
        send "{$password}\r"
        exp_continue
    }
    "Password:" {
        send "{$password}\r"
        exp_continue
    }
    eof
}
catch wait result
exit [lindex \$result 3]
EXPECT;

        // Write expect script to temp file
        $scriptFile = tempnam(sys_get_temp_dir(), 'ssh_expect_');
        file_put_contents($scriptFile, $expectScript);
        chmod($scriptFile, 0700);

        $output = [];
        $returnCode = 0;
        exec($scriptFile . " 2>&1", $output, $returnCode);

        // Clean up
        unlink($scriptFile);

        $outputStr = implode("\n", $output);
        if (!empty($outputStr)) {
            $this->log("SSH output: {$outputStr}");
        }

        if ($returnCode !== 0) {
            $this->log("SSH command failed with exit code: {$returnCode}");
            return false;
        }

        // Give the service time to restart
        $this->log("Waiting 10 seconds for service to restart...");
        sleep(10);

        return true;
    }

    /**
     * Check if a command exists
     */
    private function commandExists(string $command): bool
    {
        $output = [];
        $returnCode = 0;
        exec("which " . escapeshellarg($command) . " 2>/dev/null", $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Log a message
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] {$message}\n";

        echo $logLine;

        if (!empty($this->config['log_file'])) {
            file_put_contents($this->config['log_file'], $logLine, FILE_APPEND);
        }
    }
}

// Run the script
$manager = new UnifiCertManager($config);
exit($manager->run());
