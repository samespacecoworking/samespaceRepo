const app = {
    // State
    bookingType: null,       // 'desk' or 'room'
    selectedDates: [],       // desk: array of 'YYYY-MM-DD'
    calendarMonth: null,     // Date object for current calendar view
    selectedRoom: null,      // 'zoom' or 'meeting'
    selectedSlots: [],       // room: array of hour numbers
    availableSlots: [],      // from API
    customer: null,          // { type, coworkerId, name, email, ... }
    pricingResult: null,     // from pricing API

    // Config - room resource IDs (loaded from server or hardcoded)
    roomIds: {
        zoom: '1414928089',
        meeting: '1414931131',
    },

    // --- Navigation ---

    goTo(screenId) {
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
        document.getElementById(screenId).classList.add('active');
    },

    goBack() {
        this.goTo(this.bookingType === 'desk' ? 'screen-desk' : 'screen-room');
    },

    // --- Booking type selection ---

    selectType(type) {
        this.bookingType = type;
        if (type === 'desk') {
            this.selectedDates = [];
            this.calendarMonth = new Date();
            this.renderCalendar();
            this.goTo('screen-desk');
        } else {
            this.selectedRoom = null;
            this.selectedSlots = [];
            document.getElementById('room-date-section').classList.add('hidden');
            document.getElementById('room-slots').classList.add('hidden');
            document.getElementById('room-selection-summary').classList.add('hidden');
            document.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));
            this.goTo('screen-room');
        }
    },

    // --- Desk: Calendar ---

    renderCalendar() {
        const cal = document.getElementById('calendar');
        const month = this.calendarMonth.getMonth();
        const year = this.calendarMonth.getFullYear();

        document.getElementById('calendar-month-label').textContent =
            new Date(year, month).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // Day headers
        let html = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']
            .map(d => `<div class="cal-header">${d}</div>`).join('');

        // First day offset (Monday = 0)
        const firstDay = new Date(year, month, 1);
        let startOffset = (firstDay.getDay() + 6) % 7;

        for (let i = 0; i < startOffset; i++) {
            html += '<div class="cal-day empty"></div>';
        }

        const daysInMonth = new Date(year, month + 1, 0).getDate();

        for (let d = 1; d <= daysInMonth; d++) {
            const date = new Date(year, month, d);
            const dateStr = formatDateISO(date);
            const dayOfWeek = date.getDay();
            const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
            const isPast = date < today;
            const isSelected = this.selectedDates.includes(dateStr);
            const isToday = date.getTime() === today.getTime();

            let cls = 'cal-day';
            if (isWeekend || isPast) cls += ' disabled';
            if (isSelected) cls += ' selected';
            if (isToday) cls += ' today';

            if (isWeekend || isPast) {
                html += `<div class="${cls}">${d}</div>`;
            } else {
                html += `<button class="${cls}" onclick="app.toggleDate('${dateStr}')">${d}</button>`;
            }
        }

        cal.innerHTML = html;
        this.updateDeskSummary();
    },

    toggleDate(dateStr) {
        const idx = this.selectedDates.indexOf(dateStr);
        if (idx >= 0) {
            this.selectedDates.splice(idx, 1);
        } else {
            this.selectedDates.push(dateStr);
        }
        this.selectedDates.sort();
        this.renderCalendar();
    },

    prevMonth() {
        this.calendarMonth.setMonth(this.calendarMonth.getMonth() - 1);
        this.renderCalendar();
    },

    nextMonth() {
        this.calendarMonth.setMonth(this.calendarMonth.getMonth() + 1);
        this.renderCalendar();
    },

    updateDeskSummary() {
        const summary = document.getElementById('desk-selection-summary');
        const count = this.selectedDates.length;

        if (count === 0) {
            summary.classList.add('hidden');
            return;
        }

        summary.classList.remove('hidden');

        // Show selected dates
        const datesList = document.getElementById('desk-dates-list');
        datesList.innerHTML = '<p><strong>' + count + ' day' + (count > 1 ? 's' : '') + ' selected:</strong> ' +
            this.selectedDates.map(d => formatDateShort(d)).join(', ') + '</p>';

        // Calculate pricing
        const breakdown = calculateBulkPricing(count);
        const priceDiv = document.getElementById('desk-price-breakdown');
        priceDiv.innerHTML = breakdown.items.map(item =>
            `<p>${item.quantity} &times; ${item.name}: &pound;${(item.subtotal / 100).toFixed(2)}</p>`
        ).join('');

        document.getElementById('desk-total').textContent = '£' + (breakdown.total / 100).toFixed(2);
    },

    // --- Room ---

    selectRoom(type) {
        this.selectedRoom = type;
        this.selectedSlots = [];

        document.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));
        document.getElementById('room-' + type).classList.add('selected');

        document.getElementById('room-date-section').classList.remove('hidden');
        document.getElementById('room-slots').classList.add('hidden');
        document.getElementById('room-selection-summary').classList.add('hidden');

        // Set min date to today
        const dateInput = document.getElementById('room-date');
        dateInput.min = formatDateISO(new Date());
        dateInput.value = '';
    },

    async loadAvailability() {
        const date = document.getElementById('room-date').value;
        if (!date || !this.selectedRoom) return;

        const resourceId = this.roomIds[this.selectedRoom];
        if (!resourceId) {
            alert('Room configuration missing. Please contact Samespace.');
            return;
        }

        try {
            const res = await fetch(`/api/availability.php?resourceId=${resourceId}&date=${date}`);
            const data = await res.json();

            if (data.message) {
                document.getElementById('room-slots').classList.remove('hidden');
                document.getElementById('slot-grid').innerHTML = `<p class="subtitle">${data.message}</p>`;
                return;
            }

            this.availableSlots = data.slots;
            this.selectedSlots = [];
            this.renderSlots();
            document.getElementById('room-slots').classList.remove('hidden');
        } catch (err) {
            alert('Failed to load availability. Please try again.');
        }
    },

    renderSlots() {
        const grid = document.getElementById('slot-grid');
        grid.innerHTML = this.availableSlots.map(slot => {
            let cls = 'slot-btn';
            if (!slot.available) cls += ' unavailable';
            if (this.selectedSlots.includes(slot.hour)) cls += ' selected';

            return `<button class="${cls}" ${slot.available ? `onclick="app.toggleSlot(${slot.hour})"` : ''}>${slot.label}</button>`;
        }).join('');

        this.updateRoomSummary();
    },

    toggleSlot(hour) {
        const idx = this.selectedSlots.indexOf(hour);
        if (idx >= 0) {
            this.selectedSlots.splice(idx, 1);
        } else {
            this.selectedSlots.push(hour);
        }
        this.selectedSlots.sort((a, b) => a - b);
        this.renderSlots();
    },

    updateRoomSummary() {
        const summary = document.getElementById('room-selection-summary');
        const hours = this.selectedSlots.length;

        if (hours === 0) {
            summary.classList.add('hidden');
            return;
        }

        summary.classList.remove('hidden');
        const rate = 1000; // £10/hour default, updated after login if Business Address
        const total = hours * rate;

        const roomName = this.selectedRoom === 'zoom' ? 'Zoom Room' : 'Meeting Room';
        const date = document.getElementById('room-date').value;
        const timeRange = pad(this.selectedSlots[0]) + ':00 - ' +
            pad(this.selectedSlots[this.selectedSlots.length - 1] + 1) + ':00';

        document.getElementById('room-summary-text').innerHTML =
            `<p><strong>${roomName}</strong> &middot; ${formatDateShort(date)}</p>` +
            `<p>${timeRange} (${hours} hour${hours > 1 ? 's' : ''})</p>`;

        document.getElementById('room-total').textContent = '£' + (total / 100).toFixed(2);
    },

    // --- Account ---

    switchTab(tab) {
        document.getElementById('tab-new').classList.toggle('active', tab === 'new');
        document.getElementById('tab-existing').classList.toggle('active', tab === 'existing');
        document.getElementById('form-new').classList.toggle('hidden', tab !== 'new');
        document.getElementById('form-existing').classList.toggle('hidden', tab !== 'existing');
        document.getElementById('logged-in-info').classList.add('hidden');
        document.getElementById('login-error').classList.add('hidden');
    },

    continueAsNew() {
        const name = document.getElementById('new-name').value.trim();
        const email = document.getElementById('new-email').value.trim();
        const phone = document.getElementById('new-phone').value.trim();
        const terms = document.getElementById('new-terms').checked;

        if (!name) { alert('Please enter your name.'); return; }
        if (!email) { alert('Please enter your email.'); return; }
        if (!terms) { alert('Please accept the terms and conditions.'); return; }

        this.customer = {
            type: 'new',
            name: name,
            email: email,
            phone: phone,
            termsAccepted: true,
        };

        this.proceedToPayment();
    },

    async loginExisting() {
        const email = document.getElementById('ex-email').value.trim();
        const password = document.getElementById('ex-password').value;
        const errorEl = document.getElementById('login-error');

        if (!email || !password) {
            errorEl.textContent = 'Please enter both email and password.';
            errorEl.classList.remove('hidden');
            return;
        }

        errorEl.classList.add('hidden');

        try {
            const res = await fetch('/api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password }),
            });

            if (!res.ok) {
                const data = await res.json();
                errorEl.textContent = data.error || 'Login failed.';
                errorEl.classList.remove('hidden');
                return;
            }

            const data = await res.json();
            this.customer = {
                type: 'existing',
                coworkerId: data.coworkerId,
                name: data.name,
                email: data.email,
                unusedPasses: data.unusedPasses,
                hasBusinessAddress: data.hasBusinessAddress,
            };

            // Show logged in state
            document.getElementById('form-existing').classList.add('hidden');
            document.getElementById('form-new').classList.add('hidden');
            document.querySelector('.account-tabs').classList.add('hidden');

            document.getElementById('logged-in-info').classList.remove('hidden');
            document.getElementById('logged-in-name').textContent = data.name;

            if (data.unusedPasses > 0) {
                document.getElementById('passes-info').classList.remove('hidden');
                document.getElementById('pass-count').textContent = data.unusedPasses;
            }

            // Recalculate pricing with existing passes
            await this.updatePricingForExistingCustomer();

        } catch (err) {
            errorEl.textContent = 'Connection error. Please try again.';
            errorEl.classList.remove('hidden');
        }
    },

    async updatePricingForExistingCustomer() {
        const priceDiv = document.getElementById('updated-price');
        let requestBody;

        if (this.bookingType === 'desk') {
            requestBody = {
                type: 'desk',
                days: this.selectedDates.length,
                existingPasses: this.customer.unusedPasses,
            };
        } else {
            requestBody = {
                type: 'room',
                hours: this.selectedSlots.length,
                existingPasses: this.customer.unusedPasses,
                hasBusinessAddress: this.customer.hasBusinessAddress,
            };
        }

        try {
            const res = await fetch('/api/pricing.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(requestBody),
            });
            const data = await res.json();
            this.pricingResult = data;

            let html = '';
            if (data.existingPassesUsed > 0) {
                html += `<p>${data.existingPassesUsed} day(s) covered by existing passes</p>`;
            }
            data.lineItems.forEach(item => {
                if (item.amount > 0) {
                    html += `<p>${item.quantity} &times; ${item.name}: &pound;${(item.subtotal / 100).toFixed(2)}</p>`;
                }
            });

            if (data.total === 0) {
                html += '<p class="summary-total"><strong>No payment needed</strong></p>';
                document.getElementById('btn-pay').textContent = 'Confirm booking';
            } else {
                html += `<p class="summary-total">Total: <strong>&pound;${(data.total / 100).toFixed(2)}</strong></p>`;
                document.getElementById('btn-pay').textContent = 'Continue to payment';
            }

            priceDiv.innerHTML = html;
        } catch (err) {
            priceDiv.innerHTML = '<p class="error">Could not calculate price. Please try again.</p>';
        }
    },

    // --- Payment ---

    async proceedToPayment() {
        if (!this.customer) {
            alert('Please enter your details or log in.');
            return;
        }

        this.goTo('screen-processing');

        // Build line items and booking details
        let lineItems, checkoutBody;

        if (this.bookingType === 'desk') {
            const days = this.selectedDates.length;
            const existingPasses = this.customer.type === 'existing' ? this.customer.unusedPasses : 0;
            const pricing = this.pricingResult || calculateBulkPricingAPI(days, existingPasses);

            lineItems = pricing.lineItems || calculateBulkPricing(days - Math.min(existingPasses, days)).items.map(i => ({
                name: i.name,
                amount: i.amount,
                quantity: i.quantity,
                subtotal: i.subtotal,
            }));

            checkoutBody = {
                type: 'desk',
                dates: this.selectedDates,
                customer: this.customer,
                lineItems: lineItems,
                total: pricing.total ?? lineItems.reduce((sum, i) => sum + i.subtotal, 0),
                passesNeeded: pricing.passesNeeded ?? (days - Math.min(existingPasses, days)),
                existingPassesUsed: pricing.existingPassesUsed ?? Math.min(existingPasses, days),
            };
        } else {
            const hours = this.selectedSlots.length;
            const hasBusinessAddress = this.customer.hasBusinessAddress || false;
            const rate = hasBusinessAddress ? 500 : 1000;
            const existingPasses = this.customer.type === 'existing' ? this.customer.unusedPasses : 0;

            lineItems = [{
                name: 'Meeting room' + (hasBusinessAddress ? ' (Business Address rate)' : ''),
                amount: rate,
                quantity: hours,
                subtotal: rate * hours,
            }];

            checkoutBody = {
                type: 'room',
                roomId: this.roomIds[this.selectedRoom],
                date: document.getElementById('room-date').value,
                startHour: this.selectedSlots[0],
                endHour: this.selectedSlots[this.selectedSlots.length - 1] + 1,
                customer: this.customer,
                lineItems: lineItems,
                total: rate * hours,
                dayPassNeeded: existingPasses < 1,
            };
        }

        try {
            const res = await fetch('/api/checkout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(checkoutBody),
            });

            const data = await res.json();

            if (!res.ok) {
                alert(data.error || 'Failed to create booking.');
                this.goTo('screen-account');
                return;
            }

            if (data.redirect && data.checkoutUrl) {
                window.location.href = data.checkoutUrl;
            } else if (!data.redirect) {
                // Zero-cost booking - go straight to confirmation
                window.location.href = '/confirmation.html?intent=' + data.intentId;
            }
        } catch (err) {
            alert('Connection error. Please try again.');
            this.goTo('screen-account');
        }
    },
};

// --- Utility functions ---

function calculateBulkPricing(days) {
    const items = [];
    let remaining = days;
    let total = 0;

    const packs20 = Math.floor(remaining / 20);
    if (packs20 > 0) {
        items.push({ name: 'Day pass (20-pack)', amount: 26000, quantity: packs20, subtotal: 26000 * packs20 });
        remaining -= packs20 * 20;
        total += 26000 * packs20;
    }

    const packs4 = Math.floor(remaining / 4);
    if (packs4 > 0) {
        items.push({ name: 'Day pass (4-pack)', amount: 6500, quantity: packs4, subtotal: 6500 * packs4 });
        remaining -= packs4 * 4;
        total += 6500 * packs4;
    }

    if (remaining > 0) {
        items.push({ name: 'Day pass', amount: 1900, quantity: remaining, subtotal: 1900 * remaining });
        total += 1900 * remaining;
    }

    return { items, total };
}

function formatDateISO(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function formatDateShort(dateStr) {
    const d = new Date(dateStr + 'T12:00:00');
    return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
}

function pad(n) {
    return String(n).padStart(2, '0');
}
