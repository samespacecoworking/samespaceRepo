document.addEventListener('DOMContentLoaded', () => {
    const startScreen = document.getElementById('start-screen');
    const checkInScreen = document.getElementById('check-in-screen');
    const confirmScreen = document.getElementById('confirm-screen');
    const checkOutScreen = document.getElementById('check-out-screen');
    const nameSearch = document.getElementById('name-search');
    const clearSearch = document.getElementById('clear-search');
    const nameSuggestions = document.getElementById('name-suggestions');
    const confirmActionBtn = document.getElementById('confirm-action-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const checkedInList = document.getElementById('checked-in-list');
    const startAgainBtn = document.getElementById('start-again-btn');
    const memberCheckInBtn = document.getElementById('member-check-in-btn');
    const memberPinScreen = document.getElementById('member-pin-screen');
    const pinInputs = document.querySelectorAll('.pin-input');
    const checkInBtn = document.getElementById('visitor-check-in-btn');
    const checkOutBtn = document.getElementById('check-out-btn');
    const popupMessage = document.createElement('div');
    popupMessage.classList.add('popup-message');
    document.body.appendChild(popupMessage);

    let currentCustomer = null;
    let currentCustomerName = ''; // To store the selected name
    let isCheckingIn = false;
    let customerList = [];
    let realPin = ['', '', '', '', '', '']; // To store the actual PIN digits

    // Function to disable UI elements during popup
    function disableUIDuringPopup() {
        startAgainBtn.disabled = true; // Disable Home button
        pinInputs.forEach(input => {
            input.disabled = true; // Disable PIN inputs
        });
        if (confirmActionBtn) {
            confirmActionBtn.disabled = true; // Disable Confirm button
        }
        if (cancelBtn) {
            cancelBtn.disabled = true; // Disable Cancel button
        }
        // Optionally, remove focus from the current input to hide the soft keyboard
        document.activeElement.blur();
    }

    // Function to re-enable UI elements after popup
    function enableUIAfterPopup() {
        startAgainBtn.disabled = false; // Re-enable Home button
        pinInputs.forEach(input => {
            input.disabled = false; // Re-enable PIN inputs
        });
        if (confirmActionBtn) {
            confirmActionBtn.disabled = false; // Re-enable Confirm button
        }
        if (cancelBtn) {
            cancelBtn.disabled = false; // Re-enable Cancel button
        }
        // Optionally, focus on the first input field after re-enabling
        pinInputs[0].focus();
    }

    // Show PIN entry screen when Member Check In button is clicked
    if (memberCheckInBtn) {
        memberCheckInBtn.addEventListener('click', () => {
            startScreen.classList.remove('active');
            memberPinScreen.classList.add('active');
            pinInputs[0].focus(); // Focus on the first PIN input box
        });
    }

    pinInputs.forEach((input, index) => {
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('pattern', '[0-9]*');

        input.addEventListener('input', (e) => {
            const value = e.target.value;

            // Only allow digits
            if (!/^\d$/.test(value)) {
                e.target.value = ''; // Clear invalid input
                return;
            }

            // Store the real digit
            realPin[index] = value;

            // Replace the digit with an asterisk after 0.5 seconds
            setTimeout(() => {
                input.value = '*';
            }, 500);

            // Move to the next input box
            if (index < pinInputs.length - 1) {
                pinInputs[index + 1].focus();
            } else {
                // If last input is filled, validate the full PIN
		input.blur();
                validateMemberPin(realPin.join(''));
            }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace') {
                e.preventDefault(); // Prevent the default backspace action

                // Clear the current input
                input.value = '';
                realPin[index] = '';

                // Move focus to the previous input, if not the first input
                if (index > 0) {
                    pinInputs[index - 1].focus();
                }
            }
        });
    });

    function validateMemberPin(pin) {
        fetch('validate_member_pin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ pin: pin })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentCustomerName = data.memberName; // Store the member name
                checkInCustomer(data.coworkerId, currentCustomerName); // Pass the name to checkInCustomer
            } else {
                showPopupMessage('PIN not recognised.', true);
            }
        })
        .catch(error => {
            console.error('Error validating PIN:', error);
            showPopupMessage('An error occurred. Please try again.', true);
        });
    }

    function showPopupMessage(message, isError = false) {
        disableUIDuringPopup(); // Disable UI when showing the popup
        const popupMessage = document.querySelector('.popup-message');
        popupMessage.innerHTML = message;
        popupMessage.classList.add('visible');
        if (isError) {
            popupMessage.classList.add('error');
        } else {
            popupMessage.classList.remove('error');
        }
        setTimeout(() => {
            popupMessage.classList.remove('visible');
            enableUIAfterPopup();  // Re-enable UI after the popup has cleared
            resetAppState();  // Reset the app state only after the popup has cleared
        }, 5000); // Display popup for 5 seconds
    }

    function resetAppState() {
        startScreen.classList.add('active');
        memberPinScreen.classList.remove('active');
        checkInScreen.classList.remove('active');
        confirmScreen.classList.remove('active');
        checkOutScreen.classList.remove('active');
        realPin = ['', '', '', '', '', '']; // Clear the stored PIN
        pinInputs.forEach(input => input.value = '');
        pinInputs[0].focus(); // Reset focus to the first input box
    }

    if (checkInBtn) {
        checkInBtn.addEventListener('click', () => {
            startScreen.classList.remove('active');
            checkInScreen.classList.add('active');
            nameSearch.value = ''; // Clear the search box value
            clearSearch.classList.add('hidden'); // Hide the clear button
            nameSuggestions.innerHTML = ''; // Clear the suggestions
            loadCustomerList();
        });
    }

    if (checkOutBtn) {
        checkOutBtn.addEventListener('click', () => {
            startScreen.classList.remove('active');
            checkOutScreen.classList.add('active');
            fetchCheckedInList();
        });
    }

    if (nameSearch) {
        nameSearch.addEventListener('input', () => {
            if (nameSearch.value.length > 0) {
                clearSearch.classList.remove('hidden');
                filterCustomerNames(nameSearch.value);
            } else {
                clearSearch.classList.add('hidden');
                nameSuggestions.innerHTML = '';
            }
        });
    }

    if (clearSearch) {
        clearSearch.addEventListener('click', () => {
            nameSearch.value = '';
            clearSearch.classList.add('hidden');
            nameSuggestions.innerHTML = '';
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', resetAppState);
    }

    function loadCustomerList() {
        fetch('fetch_all_customers.php')
            .then(response => response.json())
            .then(data => {
                customerList = data;
            });
    }

    function filterCustomerNames(query) {
        const filteredNames = customerList.filter(customer =>
            customer.name.toLowerCase().includes(query.toLowerCase())
        );
        nameSuggestions.innerHTML = filteredNames.map(customer => `
            <div class="flex-item" data-id="${customer.id}">
                <span class="name">${customer.name}</span>
                <span class="company">${customer.company || 'No company'}</span>
            </div>
        `).join('');
        addNameSuggestionClickListeners();
    }

    function addNameSuggestionClickListeners() {
        document.querySelectorAll('.flex-item').forEach(item => {
            item.addEventListener('click', () => {
                currentCustomer = item.dataset.id;
                currentCustomerName = item.querySelector('.name').textContent;
                isCheckingIn = true;
                checkInScreen.classList.remove('active');
                confirmScreen.classList.add('active');
                confirmActionBtn.innerHTML = `Confirm check in <b>${currentCustomerName}</b>`;
                confirmActionBtn.removeEventListener('click', confirmCheckIn);
                confirmActionBtn.addEventListener('click', confirmCheckIn);
            });
        });
    }

    function fetchCheckedInList() {
        fetch('who_is_in.php')
            .then(response => response.json())
            .then(data => {
                checkedInList.innerHTML = data.map(customer => `
                    <div class="flex-item" data-id="${customer.id}">
                        <span class="name">${customer.name || 'No name'}</span>
                        <span class="company">${customer.company || 'No company'}</span>
                    </div>
                `).join('');
                addCheckedInListClickListeners();
            })
            .catch(error => {
                console.error('Error fetching checked-in list:', error);
            });
    }

    function addCheckedInListClickListeners() {
        document.querySelectorAll('.flex-item').forEach(item => {
            item.addEventListener('click', () => {
                currentCustomer = item.dataset.id;
                currentCustomerName = item.querySelector('.name').textContent;
                isCheckingIn = false;
                checkOutScreen.classList.remove('active');
                confirmScreen.classList.add('active');
                confirmActionBtn.innerHTML = `Confirm check out <b>${currentCustomerName}</b>`;
                confirmActionBtn.removeEventListener('click', confirmCheckOut);
                confirmActionBtn.addEventListener('click', confirmCheckOut);
            });
        });
    }

    function confirmCheckIn() {
        if (isCheckingIn) {
            checkInCustomer(currentCustomer, currentCustomerName);
        }
    }

    function confirmCheckOut() {
        if (!isCheckingIn) {
            checkOutCustomer(currentCustomer);
        }
    }

    function checkInCustomer(customerId, customerName) {
        fetch('check_in.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id: customerId })
        })
        .then(response => {
            if (!response.ok) {
                return response.json().then(data => { throw new Error(data.error); });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showPopupMessage(`Checked in <b>${customerName}</b>`);
            } else {
                throw new Error(data.error || 'Unknown error');
            }
        })
        .catch(error => {
            if (error.message === 'already_checked_in') {
                showPopupMessage(`<b>${customerName}</b> is already checked in.`, true);
            } else {
                showPopupMessage(`Failed to check in <b>${customerName}</b>.`);
            }
        });
    }
    
    function checkOutCustomer(customerId) {
        fetch('check_out.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id: customerId })
        }).then(() => {
            showPopupMessage(`Checked out <b>${currentCustomerName}</b>`);
        }).catch(error => {
            console.error('Error checking customer out:', error);
        });
    }

    if (startAgainBtn) {
        startAgainBtn.addEventListener('click', resetAppState);
    }
});
