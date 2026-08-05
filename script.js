/* ==========================================================================
   Healthcore Patient Portal Interactive JavaScript Application Logic
   ========================================================================== */

document.addEventListener('DOMContentLoaded', () => {
    // ----------------------------------------------------------------------
    // 1. Initial State & Local Storage Setup
    // ----------------------------------------------------------------------
    const defaultState = {
        theme: 'light',
        appointment: {
            doctor: 'Dr. Sarah Lee',
            department: 'Cardiology Department • Room 304',
            date: '29 February 2026',
            time: '10:30 AM',
            status: 'Confirmed'
        },
        profile: {
            name: 'John Doe',
            email: 'john.doe@example.com',
            phone: '+1 (555) 234-5678',
            address: '742 Evergreen Terrace, Springfield, OR',
            emergencyName: 'Mary Doe',
            emergencyPhone: '+1 (555) 876-5432'
        }
    };

    // Load state from localStorage or initialize defaults
    let appState = JSON.parse(localStorage.getItem('healthcore_state')) || defaultState;

    function saveState() {
        localStorage.setItem('healthcore_state', JSON.stringify(appState));
    }

    // ----------------------------------------------------------------------
    // 2. Theme Switching (Light/Dark Mode)
    // ----------------------------------------------------------------------
    function initTheme() {
        const htmlEl = document.documentElement;
        htmlEl.setAttribute('data-theme', appState.theme);
        updateThemeBtnIcon();
    }

    function toggleTheme() {
        appState.theme = appState.theme === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', appState.theme);
        saveState();
        updateThemeBtnIcon();
        showToast(`Switched to ${appState.theme.toUpperCase()} mode`, appState.theme === 'dark' ? '🌙' : '☀️');
    }

    function updateThemeBtnIcon() {
        const themeBtn = document.getElementById('themeToggleBtn');
        if (themeBtn) {
            themeBtn.textContent = appState.theme === 'dark' ? '☀️' : '🌙';
        }
    }

    const themeToggleBtn = document.getElementById('themeToggleBtn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', toggleTheme);
    }

    const setLightThemeBtn = document.getElementById('setLightThemeBtn');
    const setDarkThemeBtn = document.getElementById('setDarkThemeBtn');
    if (setLightThemeBtn) setLightThemeBtn.addEventListener('click', () => { appState.theme = 'light'; initTheme(); saveState(); showToast('Light mode set', '☀️'); });
    if (setDarkThemeBtn) setDarkThemeBtn.addEventListener('click', () => { appState.theme = 'dark'; initTheme(); saveState(); showToast('Dark mode set', '🌙'); });

    initTheme();

    // ----------------------------------------------------------------------
    // 2b. Dynamic Navbar Active Link & ScrollSpy Logic
    // ----------------------------------------------------------------------
    const navLinks = document.querySelectorAll('.nav-link-custom');

    function setActiveNavLink(targetLink) {
        navLinks.forEach(link => link.classList.remove('active'));
        if (targetLink) {
            targetLink.classList.add('active');
        }
    }

    navLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            setActiveNavLink(this);

            if (href && href.includes('#')) {
                const targetId = href.substring(href.indexOf('#'));
                const targetEl = document.querySelector(targetId);
                if (targetEl) {
                    e.preventDefault();
                    targetEl.scrollIntoView({ behavior: 'smooth' });
                    history.pushState(null, null, targetId);
                }
            }
        });
    });

    // Auto-update active link based on current hash or scroll position
    function checkCurrentSection() {
        if (window.scrollY < 120 && navLinks.length > 0) {
            setActiveNavLink(navLinks[0]);
            return;
        }

        const hash = window.location.hash;
        if (hash) {
            const matchingLink = document.querySelector(`.nav-link-custom[href*="${hash}"]`);
            if (matchingLink) {
                setActiveNavLink(matchingLink);
                return;
            }
        }
    }

    // Scrollspy using IntersectionObserver
    const trackedSections = document.querySelectorAll('section[id], div[id]');
    if ('IntersectionObserver' in window && trackedSections.length > 0) {
        const observerOptions = {
            root: null,
            rootMargin: '-20% 0px -60% 0px',
            threshold: 0
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && window.scrollY >= 120) {
                    const id = entry.target.getAttribute('id');
                    const matchingLink = document.querySelector(`.nav-link-custom[href*="#${id}"]`);
                    if (matchingLink) {
                        setActiveNavLink(matchingLink);
                    }
                }
            });
        }, observerOptions);

        trackedSections.forEach(section => observer.observe(section));
    }

    window.addEventListener('scroll', () => {
        if (window.scrollY < 120 && navLinks.length > 0) {
            setActiveNavLink(navLinks[0]);
        }
    });

    checkCurrentSection();

    // ----------------------------------------------------------------------
    // 3. Render Dashboard State (Appointments & Queue)
    // ----------------------------------------------------------------------
    function renderDashboard() {
        // Appointments
        const apptDateEl = document.getElementById('apptDate');
        const apptTimeEl = document.getElementById('apptTime');
        const apptDoctorEl = document.getElementById('apptDoctor');
        const apptDeptEl = document.getElementById('apptDept');

        if (apptDateEl) apptDateEl.textContent = appState.appointment.date;
        if (apptTimeEl) apptTimeEl.textContent = appState.appointment.time;
        if (apptDoctorEl) apptDoctorEl.textContent = appState.appointment.doctor;
        if (apptDeptEl) apptDeptEl.textContent = appState.appointment.department;

        // Modal appointment details
        const detailDoctor = document.getElementById('detailDoctor');
        const detailDept = document.getElementById('detailDept');
        const detailDate = document.getElementById('detailDate');
        const detailTime = document.getElementById('detailTime');
        if (detailDoctor) detailDoctor.textContent = appState.appointment.doctor;
        if (detailDept) detailDept.textContent = appState.appointment.department;
        if (detailDate) detailDate.textContent = appState.appointment.date;
        if (detailTime) detailTime.textContent = appState.appointment.time;
    }

    renderDashboard();

    // ----------------------------------------------------------------------
    // 4. Live Queue Status (real, server-backed, polled — not simulated)
    // ----------------------------------------------------------------------
    // Pulls the actual row from `queue_state` (and today's real ticket
    // numbers) via queue_status.php and refreshes every few seconds, on
    // both the patient dashboard and the admin/doctor queue controller.
    // Whichever elements exist on the current page get updated; the ones
    // that don't apply (e.g. admin-only IDs on the patient page) are
    // simply skipped.
    let lastKnownServing = null;
    // Tracks which of this patient's own ticket numbers we've already
    // shown the "your turn" popup for, so it fires exactly once per
    // ticket (not on every 5-second poll while it stays their turn).
    let turnNotifiedForTicket = null;

    // Patient dashboard: one queue widget for whichever single department
    // their next appointment is in (department comes from the widget's
    // own data-department attribute — see index.php).
    function updateQueueDomPatient(data) {
        const myQueueEl = document.getElementById('myQueueNumber');
        const currentQueueEl = document.getElementById('currentQueueNumber');
        const patientsAheadEl = document.getElementById('patientsAheadCount');
        const estimatedWaitEl = document.getElementById('estimatedWaitTime');
        const noTicketNoteEl = document.getElementById('queueNoTicketNote');

        if (typeof data.myTicket !== 'undefined') {
            if (myQueueEl) myQueueEl.textContent = data.myTicket ? data.myTicket : '—';
            if (patientsAheadEl) patientsAheadEl.textContent = data.myTicket ? data.ahead : '—';
            if (estimatedWaitEl) estimatedWaitEl.textContent = data.myTicket ? `${data.estWaitMins} mins` : '—';
            // Only relevant once a ticket actually exists — e.g. midnight
            // rolls over while the page is open and today's ticket appears.
            if (noTicketNoteEl) noTicketNoteEl.style.display = data.myTicket ? 'none' : '';
        }
        if (currentQueueEl) currentQueueEl.textContent = data.currentlyServing;

        // Patient "Queue Tracker" modal
        const modalMyQueue = document.getElementById('modalMyQueue');
        const modalServingQueue = document.getElementById('modalServingQueue');
        const modalAheadQueue = document.getElementById('modalAheadQueue');
        if (typeof data.myTicket !== 'undefined') {
            if (modalMyQueue) modalMyQueue.textContent = data.myTicket ? data.myTicket : '—';
            if (modalAheadQueue) modalAheadQueue.textContent = data.ahead;
        }
        if (modalServingQueue) modalServingQueue.textContent = data.currentlyServing;

        // Toast the patient when a new ticket gets called while on the page
        if (lastKnownServing !== null && data.currentlyServing > lastKnownServing) {
            const isMyTurn = data.myTicket && data.currentlyServing >= data.myTicket;
            if (!isMyTurn) {
                showToast(`Now Serving Ticket #${data.currentlyServing}`, '🎫');
            }
        }
        lastKnownServing = data.currentlyServing;

        // Dedicated "it's your turn" popup — checked on every poll (not
        // just when currentlyServing changes) so it also fires if the
        // page is opened right as it becomes this patient's turn, and
        // fires exactly once per ticket via turnNotifiedForTicket.
        if (data.myTicket && data.currentlyServing >= data.myTicket && turnNotifiedForTicket !== data.myTicket) {
            showToast('Your turn now! Please proceed to the counter.', '🔔', 10000);
            turnNotifiedForTicket = data.myTicket;
        }
    }

    function pollPatientQueue() {
        const queueSection = document.getElementById('queue-section');
        if (!queueSection) return;
        const department = queueSection.dataset.department || '';
        if (!department) return; // no upcoming appointment → nothing to poll
        fetch(`queue_status.php?department=${encodeURIComponent(department)}`, { credentials: 'same-origin' })
            .then(res => res.ok ? res.json() : Promise.reject(res.status))
            .then(updateQueueDomPatient)
            .catch(() => { /* e.g. session expired mid-poll — just skip this tick */ });
    }

    // Admin / doctor queue controller: one panel PER DEPARTMENT (a doctor
    // only ever has their own single department's panel on the page — see
    // admin.php — an admin has one per active department). Each panel is
    // scoped with querySelector on itself so multiple departments' numbers
    // never collide, and each is polled independently with its own
    // ?department= so the server returns that department's real counts.
    function updateDeptPanel(panel, data) {
        const servingEl = panel.querySelector('.dept-serving-ticket');
        const nextEl = panel.querySelector('.dept-next-ticket');
        const waitingEl = panel.querySelector('.dept-waiting-count');
        const progressEl = panel.querySelector('.dept-progress-bar');
        const callNextBtn = panel.querySelector('.dept-call-next-btn');

        if (servingEl) servingEl.textContent = data.currentlyServing;
        if (nextEl) nextEl.textContent = data.nextTicket;
        if (waitingEl) waitingEl.textContent = data.waiting;
        if (progressEl) progressEl.style.width = `${data.progressPct}%`;
        // Keep in sync with the server-side guard: nothing to call
        // next once no one is actually waiting in that department.
        if (callNextBtn) callNextBtn.disabled = data.waiting === 0;

        const prevServing = parseInt(panel.dataset.lastServing || '-1', 10);
        if (prevServing >= 0 && data.currentlyServing > prevServing) {
            showToast(`${panel.dataset.department}: Now Serving Ticket #${data.currentlyServing}`, '🎫');
        }
        panel.dataset.lastServing = data.currentlyServing;
    }

    function pollDeptPanels() {
        document.querySelectorAll('.dept-queue-panel').forEach(panel => {
            const department = panel.dataset.department;
            if (!department) return;
            fetch(`queue_status.php?department=${encodeURIComponent(department)}`, { credentials: 'same-origin' })
                .then(res => res.ok ? res.json() : Promise.reject(res.status))
                .then(data => updateDeptPanel(panel, data))
                .catch(() => { /* skip this tick */ });
        });
    }

    function pollQueueStatus() {
        if (document.getElementById('queue-section')) pollPatientQueue();
        if (document.querySelector('.dept-queue-panel')) pollDeptPanels();
    }

    if (document.getElementById('queue-section') || document.querySelector('.dept-queue-panel')) {
        pollQueueStatus();
        setInterval(pollQueueStatus, 5000);
    }

    // ----------------------------------------------------------------------
    // 5. Appointment Booking Form Handling
    // ----------------------------------------------------------------------
    const bookAppointmentForm = document.getElementById('bookAppointmentForm');
    if (bookAppointmentForm) {
        bookAppointmentForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const dept = document.getElementById('deptSelect').value;
            const doctor = document.getElementById('doctorSelect').value;
            const rawDate = document.getElementById('apptDateInput').value;
            const time = document.getElementById('apptTimeInput').value;

            // Format raw YYYY-MM-DD into readable date
            let formattedDate = rawDate;
            if (rawDate) {
                const dateObj = new Date(rawDate);
                const options = { day: 'numeric', month: 'long', year: 'numeric' };
                formattedDate = dateObj.toLocaleDateString('en-GB', options);
            }

            appState.appointment = {
                doctor: doctor || 'Dr. Sarah Lee',
                department: dept || 'Cardiology Department',
                date: formattedDate || '29 February 2026',
                time: time || '10:30 AM',
                status: 'Confirmed'
            };

            saveState();
            renderDashboard();

            // Close modal safely
            const modalEl = document.getElementById('bookAppointmentModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            showToast('Appointment successfully scheduled!', '✅');
        });
    }

    // ----------------------------------------------------------------------
    // 5b. Doctor Time-Slot Availability (real, server-backed check)
    // ----------------------------------------------------------------------
    // Whenever the physician or date changes on the real booking form,
    // ask get_slots.php which of that doctor's slots are already taken
    // that day and disable them in the dropdown — so a patient can't even
    // select a slot someone else already holds. The server still re-checks
    // this at submit time (see index.php), since a disabled <option> can
    // always be bypassed client-side.
    const doctorSelectPHP = document.getElementById('doctorSelectPHP');
    const apptDateSelectPHP = document.getElementById('apptDateSelectPHP');
    const apptTimeSelectPHP = document.getElementById('apptTimeSelectPHP');
    const slotAvailabilityNote = document.getElementById('slotAvailabilityNote');

    function refreshSlotAvailability() {
        if (!doctorSelectPHP || !apptDateSelectPHP || !apptTimeSelectPHP) return;
        const doctorId = doctorSelectPHP.value;
        const apptDate = apptDateSelectPHP.value;

        // Reset to "all enabled" whenever the inputs aren't complete yet.
        if (!doctorId || !apptDate) {
            [...apptTimeSelectPHP.options].forEach(opt => { opt.disabled = false; });
            if (slotAvailabilityNote) slotAvailabilityNote.textContent = 'Choose a physician and date to see open slots.';
            return;
        }

        if (slotAvailabilityNote) slotAvailabilityNote.textContent = 'Checking availability…';

        fetch(`get_slots.php?doctor_id=${encodeURIComponent(doctorId)}&appt_date=${encodeURIComponent(apptDate)}`, { credentials: 'same-origin' })
            .then(res => res.ok ? res.json() : Promise.reject(res.status))
            .then(data => {
                if (!data.slots) return;
                let anyAvailable = false;
                let selectedNowTaken = false;

                data.slots.forEach(slot => {
                    const opt = [...apptTimeSelectPHP.options].find(o => o.value === slot.time);
                    if (!opt) return;
                    opt.disabled = !slot.available;
                    opt.textContent = slot.available ? slot.time : `${slot.time} (Booked)`;
                    if (slot.available) anyAvailable = true;
                    if (!slot.available && opt.selected) selectedNowTaken = true;
                });

                // If the currently-selected slot just became unavailable
                // (e.g. patient switched doctor/date), jump to the first
                // open one instead of silently submitting a taken slot.
                if (selectedNowTaken) {
                    const firstOpen = [...apptTimeSelectPHP.options].find(o => !o.disabled);
                    if (firstOpen) firstOpen.selected = true;
                }

                if (slotAvailabilityNote) {
                    slotAvailabilityNote.textContent = anyAvailable
                        ? 'Booked slots are disabled below.'
                        : 'This physician is fully booked that day — try another date.';
                }
            })
            .catch(() => {
                if (slotAvailabilityNote) slotAvailabilityNote.textContent = 'Could not check availability — slots shown may not be accurate.';
            });
    }

    if (doctorSelectPHP && apptDateSelectPHP) {
        doctorSelectPHP.addEventListener('change', refreshSlotAvailability);
        apptDateSelectPHP.addEventListener('change', refreshSlotAvailability);
    }

    // Cancel Appointment button
    const cancelApptBtn = document.getElementById('cancelApptBtn');
    if (cancelApptBtn) {
        cancelApptBtn.addEventListener('click', () => {
            if (confirm('Are you sure you want to cancel your upcoming appointment?')) {
                appState.appointment = {
                    doctor: 'No upcoming appointment',
                    department: 'Schedule a visit using the book button',
                    date: 'N/A',
                    time: '--:--',
                    status: 'Cancelled'
                };
                saveState();
                renderDashboard();

                const modalEl = document.getElementById('appointmentDetailModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }

                showToast('Appointment cancelled', 'ℹ️');
            }
        });
    }

    const rescheduleBtn = document.getElementById('rescheduleBtn');
    if (rescheduleBtn) {
        rescheduleBtn.addEventListener('click', () => {
            const modalEl = new bootstrap.Modal(document.getElementById('bookAppointmentModal'));
            modalEl.show();
        });
    }

    // ----------------------------------------------------------------------
    // 6. Medical Records Viewing
    // ----------------------------------------------------------------------
    const recordDetailsData = {
        'REC-001': {
            title: 'Comprehensive Blood Panel Result',
            date: '24 Jan 2026',
            physician: 'Dr. Sarah Lee',
            summary: 'Lipid Profile & Complete Blood Count (CBC)',
            content: `
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 border rounded">
                            <small class="text-muted">Total Cholesterol</small>
                            <h5 class="text-success manrope-700">182 mg/dL <span class="badge bg-success fs-6">Normal</span></h5>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded">
                            <small class="text-muted">Fasting Blood Sugar</small>
                            <h5 class="text-success manrope-700">92 mg/dL <span class="badge bg-success fs-6">Normal</span></h5>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded">
                            <small class="text-muted">Hemoglobin (Hb)</small>
                            <h5 class="text-success manrope-700">15.4 g/dL <span class="badge bg-success fs-6">Normal</span></h5>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded">
                            <small class="text-muted">Platelet Count</small>
                            <h5 class="text-success manrope-700">260,000 /mcL <span class="badge bg-success fs-6">Normal</span></h5>
                        </div>
                    </div>
                </div>
                <div class="mt-3 p-3 rounded border">
                    <strong>Physician Remarks:</strong>
                    <p class="mb-0 text-muted">All metabolic and hematologic parameters are well within reference ranges. Continue present diet and exercise program.</p>
                </div>
            `
        },
        'REC-002': {
            title: 'ECG & Cardiology Evaluation Report',
            date: '10 Dec 2025',
            physician: 'Dr. Sarah Lee',
            summary: 'Resting Electrocardiogram (12-Lead)',
            content: `
                <div class="p-3 border rounded mb-3">
                    <h6 class="manrope-700">ECG Findings:</h6>
                    <ul>
                        <li>Normal Sinus Rhythm (NSR) at 72 bpm</li>
                        <li>Normal PR interval and QRS duration</li>
                        <li>No ST-T wave abnormalities detected</li>
                    </ul>
                </div>
                <div class="p-3 rounded border">
                    <strong>Physician Impression:</strong>
                    <p class="mb-0 text-muted">Healthy cardiac rhythm without ischemic changes. Follow-up consultation scheduled in 6 months.</p>
                </div>
            `
        },
        'REC-003': {
            title: 'Annual Wellness Checkup Summary',
            date: '15 Nov 2025',
            physician: 'Dr. Robert Chen',
            summary: 'Routine Health Assessment',
            content: `
                <div class="row g-2 mb-3">
                    <div class="col-6"><p class="mb-1"><strong>Blood Pressure:</strong> 120/78 mmHg</p></div>
                    <div class="col-6"><p class="mb-1"><strong>Heart Rate:</strong> 68 bpm</p></div>
                    <div class="col-6"><p class="mb-1"><strong>BMI:</strong> 23.4 (Normal)</p></div>
                    <div class="col-6"><p class="mb-1"><strong>SpO2:</strong> 99%</p></div>
                </div>
                <p class="text-muted">Patient is in excellent overall health. Vaccines up to date.</p>
            `
        }
    };

    document.querySelectorAll('.view-record-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const recId = btn.getAttribute('data-record-id');
            const data = recordDetailsData[recId];

            if (data) {
                const titleEl = document.getElementById('recordModalTitle');
                const bodyEl = document.getElementById('recordModalBody');

                if (titleEl) titleEl.textContent = data.title;
                if (bodyEl) {
                    bodyEl.innerHTML = `
                        <div class="mb-3 border-bottom pb-2">
                            <small class="text-muted">Date: ${data.date} | Physician: ${data.physician}</small>
                            <h6 class="text-primary mt-1">${data.summary}</h6>
                        </div>
                        ${data.content}
                    `;
                }

                const modal = new bootstrap.Modal(document.getElementById('recordViewModal'));
                modal.show();
            }
        });
    });

    // ----------------------------------------------------------------------
    // 7. Profile Page Interactions
    // ----------------------------------------------------------------------
    function renderProfile() {
        const pName = document.getElementById('profileNameDisplay');
        const pEmail = document.getElementById('profileEmailDisplay');
        const pPhone = document.getElementById('profilePhoneDisplay');
        const pAddress = document.getElementById('profileAddressDisplay');
        const pEmergName = document.getElementById('profileEmergencyName');
        const pEmergPhone = document.getElementById('profileEmergencyPhone');

        if (pName) pName.textContent = appState.profile.name;
        if (pEmail) pEmail.textContent = appState.profile.email;
        if (pPhone) pPhone.textContent = appState.profile.phone;
        if (pAddress) pAddress.textContent = appState.profile.address;
        if (pEmergName) pEmergName.textContent = appState.profile.emergencyName;
        if (pEmergPhone) pEmergPhone.textContent = `Phone: ${appState.profile.emergencyPhone}`;
    }

    renderProfile();

    const editProfileForm = document.getElementById('editProfileForm');
    if (editProfileForm) {
        editProfileForm.addEventListener('submit', (e) => {
            e.preventDefault();
            appState.profile.name = document.getElementById('editNameInput').value;
            appState.profile.email = document.getElementById('editEmailInput').value;
            appState.profile.phone = document.getElementById('editPhoneInput').value;
            appState.profile.address = document.getElementById('editAddressInput').value;
            appState.profile.emergencyName = document.getElementById('editEmergencyNameInput').value;
            appState.profile.emergencyPhone = document.getElementById('editEmergencyPhoneInput').value;

            saveState();
            renderProfile();

            const modalEl = document.getElementById('editProfileModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            showToast('Profile information updated', '👤');
        });
    }

    // ----------------------------------------------------------------------
    // 8. Settings Page Forms & Actions
    // ----------------------------------------------------------------------
    const notificationsForm = document.getElementById('notificationsForm');
    if (notificationsForm) {
        notificationsForm.addEventListener('submit', (e) => {
            e.preventDefault();
            showToast('Notification preferences saved', '🔔');
        });
    }

    const passwordChangeForm = document.getElementById('passwordChangeForm');
    if (passwordChangeForm) {
        passwordChangeForm.addEventListener('submit', (e) => {
            e.preventDefault();
            showToast('Security settings updated successfully', '🔒');
            passwordChangeForm.reset();
        });
    }

    // Quick Refill Rx & Export Buttons
    const refillRxBtn = document.getElementById('refillRxBtn');
    if (refillRxBtn) {
        refillRxBtn.addEventListener('click', (e) => {
            e.preventDefault();
            showToast('Refill request sent to Pharmacy Department', '💊');
        });
    }

    const downloadAllRecordsBtn = document.getElementById('downloadAllRecordsBtn');
    if (downloadAllRecordsBtn) {
        downloadAllRecordsBtn.addEventListener('click', () => {
            showToast('Preparing medical records PDF download...', '📄');
            setTimeout(() => {
                showToast('Medical_Records_John_Doe.pdf downloaded!', '📥');
            }, 1500);
        });
    }

    // Note: logout is a real link (logout.php) on every PHP page now, so it
    // needs no JS handler — the server destroys the session directly.

    // ----------------------------------------------------------------------
    // 9. Admin Portal Logic
    // ----------------------------------------------------------------------
    // Note: "Call Next Ticket" and "Reset Queue" are real <form>
    // submissions straight to admin.php (see section 4 above for the
    // live polling that reflects the result on every open tab/page).

    // Admin Record Search + Status Filter
    const adminRecordSearch = document.getElementById('adminRecordSearch');
    const adminStatusFilter = document.getElementById('adminStatusFilter');
    function applyAdminRecordFilters() {
        const query = (adminRecordSearch?.value || '').toLowerCase();
        const status = adminStatusFilter?.value || '';
        const rows = document.querySelectorAll('#adminRecordsTableBody tr');

        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            const matchesText = text.includes(query);
            const matchesStatus = !status || row.dataset.status === status;
            row.style.display = (matchesText && matchesStatus) ? '' : 'none';
        });
    }
    if (adminRecordSearch) adminRecordSearch.addEventListener('input', applyAdminRecordFilters);
    if (adminStatusFilter) adminStatusFilter.addEventListener('change', applyAdminRecordFilters);

    // View Medical Record modal
    document.querySelectorAll('.view-record-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('viewRecPatient').textContent = btn.dataset.patient || '—';
            document.getElementById('viewRecDoctor').textContent = btn.dataset.doctor || '—';
            document.getElementById('viewRecDate').textContent = btn.dataset.date || '—';
            document.getElementById('viewRecStatus').textContent = btn.dataset.status || '—';
            document.getElementById('viewRecTitle').textContent = btn.dataset.title || '';
            document.getElementById('viewRecNotes').textContent = btn.dataset.notes || 'No notes recorded.';
            const modalEl = document.getElementById('viewRecordModal');
            if (modalEl) new bootstrap.Modal(modalEl).show();
        });
    });

    // Edit Medical Record modal
    document.querySelectorAll('.edit-record-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('editRecId').value = btn.dataset.id || '';
            document.getElementById('editRecPatient').value = btn.dataset.patientId || '';
            document.getElementById('editRecDoctor').value = btn.dataset.doctorId || '';
            document.getElementById('editRecTitle').value = btn.dataset.title || '';
            document.getElementById('editRecNotes').value = btn.dataset.notes || '';
            document.getElementById('editRecDate').value = btn.dataset.date || '';
            const modalEl = document.getElementById('editRecordModal');
            if (modalEl) new bootstrap.Modal(modalEl).show();
        });
    });

    // Mark Appointment Completed
    document.querySelectorAll('.mark-complete-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const row = e.target.closest('tr');
            const statusCell = row.querySelector('.badge');
            if (statusCell) {
                statusCell.className = 'badge bg-success';
                statusCell.textContent = 'Completed';
            }
            e.target.disabled = true;
            e.target.textContent = '✔️ Done';
            showToast('Consultation marked as completed', '✅');
        });
    });

    // Add New Medical Record Form
    const addRecordForm = document.getElementById('addRecordForm');
    if (addRecordForm) {
        addRecordForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const title = document.getElementById('newRecTitle').value || 'Diagnostic Exam';
            const doctor = document.getElementById('newRecDoctor').value || 'Dr. Sarah Lee';
            const today = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

            // Add row to Admin Table
            const adminTbody = document.getElementById('adminRecordsTableBody');
            if (adminTbody) {
                const newRow = document.createElement('tr');
                newRow.innerHTML = `
                    <td><strong>#HC-99210</strong></td>
                    <td>John Doe</td>
                    <td>${title}</td>
                    <td>${doctor}</td>
                    <td>${today}</td>
                    <td><span class="badge bg-success">Verified</span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-custom view-admin-record">Inspect</button>
                    </td>
                `;
                adminTbody.prepend(newRow);
            }

            // Add row to Patient Table if present
            const patientTbody = document.getElementById('medicalRecordsTableBody');
            if (patientTbody) {
                const newRow = document.createElement('tr');
                newRow.innerHTML = `
                    <td>${today}</td>
                    <td><strong>${title}</strong></td>
                    <td>${doctor}</td>
                    <td>Main Hospital Lab</td>
                    <td><span class="badge bg-success">Completed</span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-custom view-record-btn" data-record-id="REC-001">View</button>
                    </td>
                `;
                patientTbody.prepend(newRow);
            }

            const modalEl = document.getElementById('addRecordModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            addRecordForm.reset();
            showToast('New EMR medical record published to patient portal!', '📑');
        });
    }

    // ----------------------------------------------------------------------
    // 10. Toast Notification Generator Utility
    // ----------------------------------------------------------------------
    function showToast(message, icon = 'ℹ️', durationMs = 3500) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'toast-custom';
        toast.innerHTML = `
            <span class="fs-5">${icon}</span>
            <span>${message}</span>
        `;

        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, durationMs);
    }
});

