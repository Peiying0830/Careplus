// Tab Navigation
const navItems = document.querySelectorAll('.nav-item');
const tabContents = document.querySelectorAll('.tab-content');

navItems.forEach(item => {
    item.addEventListener('click', () => {
        // Remove active class from all items
        navItems.forEach(nav => nav.classList.remove('active'));
        tabContents.forEach(tab => tab.classList.remove('active'));
        
        // Add active class to clicked item
        item.classList.add('active');
        const tabId = item.getAttribute('data-tab');
        document.getElementById(tabId).classList.add('active');
        
        // Save active tab to localStorage
        localStorage.setItem('activeSettingsTab', tabId);
    });
});

// Restore last active tab on page load
window.addEventListener('load', function() {
    const activeTab = localStorage.getItem('activeSettingsTab');
    if (activeTab) {
        const navItem = document.querySelector(`[data-tab="${activeTab}"]`);
        if (navItem) {
            navItem.click();
        }
    }
});

// Deactivate Modal Functions
function openDeactivateModal() {
    document.getElementById('deactivateModal').classList.add('active');
}

function closeDeactivateModal() {
    document.getElementById('deactivateModal').classList.remove('active');
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('deactivateModal');
    if (event.target === modal) {
        closeDeactivateModal();
    }
});

// Password validation
const passwordForms = document.querySelectorAll('form');
passwordForms.forEach(form => {
    form.addEventListener('submit', function(e) {
        const action = form.querySelector('input[name="action"]')?.value;
        
        if (action === 'change_password') {
            const newPassword = form.querySelector('input[name="new_password"]').value;
            const confirmPassword = form.querySelector('input[name="confirm_password"]').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New passwords do not match!');
                return false;
            }
            
            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
        }
    });
});

// Auto-hide alerts after 5 seconds
window.addEventListener('load', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });
});

// Toggle notification switches
const toggles = document.querySelectorAll('.toggle input[type="checkbox"]');
toggles.forEach(toggle => {
    toggle.addEventListener('change', function() {
        // You can add AJAX call here to save preference immediately
        console.log(`${this.name} changed to:`, this.checked);
    });
});

// Email validation
const emailInputs = document.querySelectorAll('input[type="email"]');
emailInputs.forEach(input => {
    input.addEventListener('blur', function() {
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(this.value)) {
            this.style.borderColor = '#ef4444';
            this.setCustomValidity('Please enter a valid email address');
        } else {
            this.style.borderColor = '#16a34a';
            this.setCustomValidity('');
        }
    });
    
    input.addEventListener('input', function() {
        this.style.borderColor = '#e5e7eb';
        this.setCustomValidity('');
    });
});

// Password strength indicator
const passwordInputs = document.querySelectorAll('input[type="password"][name="new_password"]');
passwordInputs.forEach(input => {
    // Create strength indicator
    const strengthDiv = document.createElement('div');
    strengthDiv.className = 'password-strength';
    strengthDiv.innerHTML = `
        <div class="strength-bar">
            <div class="strength-fill"></div>
        </div>
        <small class="strength-text"></small>
    `;
    input.parentElement.appendChild(strengthDiv);
    
    // Add strength indicator styles
    const style = document.createElement('style');
    style.textContent = `
        .password-strength {
            margin-top: 8px;
        }
        .strength-bar {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
        }
        .strength-text {
            font-size: 12px;
            font-weight: 600;
        }
    `;
    document.head.appendChild(style);
    
    input.addEventListener('input', function() {
        const password = this.value;
        const strengthBar = this.parentElement.querySelector('.strength-fill');
        const strengthText = this.parentElement.querySelector('.strength-text');
        
        let strength = 0;
        let strengthLabel = '';
        let strengthColor = '';
        
        // Check password strength
        if (password.length >= 8) strength++;
        if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
        if (password.match(/[0-9]/)) strength++;
        if (password.match(/[^a-zA-Z0-9]/)) strength++;
        
        // Set strength level
        switch(strength) {
            case 0:
            case 1:
                strengthLabel = 'Weak';
                strengthColor = '#ef4444';
                break;
            case 2:
                strengthLabel = 'Fair';
                strengthColor = '#f59e0b';
                break;
            case 3:
                strengthLabel = 'Good';
                strengthColor = '#3b82f6';
                break;
            case 4:
                strengthLabel = 'Strong';
                strengthColor = '#16a34a';
                break;
        }
        
        // Update UI
        strengthBar.style.width = `${(strength / 4) * 100}%`;
        strengthBar.style.background = strengthColor;
        strengthText.textContent = password.length > 0 ? `Password strength: ${strengthLabel}` : '';
        strengthText.style.color = strengthColor;
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Close modal with Escape
    if (e.key === 'Escape') {
        closeDeactivateModal();
    }
    
    // Navigate tabs with Ctrl+Number
    if (e.ctrlKey && e.key >= '1' && e.key <= '5') {
        e.preventDefault();
        const index = parseInt(e.key) - 1;
        navItems[index]?.click();
    }
});

// Confirm before leaving page with unsaved changes
let formChanged = false;
const formInputs = document.querySelectorAll('.setting-form input, .setting-form select, .setting-form textarea');

formInputs.forEach(input => {
    input.addEventListener('input', function() {
        formChanged = true;
    });
});

document.querySelectorAll('.setting-form').forEach(form => {
    form.addEventListener('submit', function() {
        formChanged = false;
    });
});

window.addEventListener('beforeunload', function(e) {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        return e.returnValue;
    }
});

// Animate settings cards on load
window.addEventListener('load', function() {
    const settingCards = document.querySelectorAll('.setting-card');
    settingCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});

// Add confirmation for dangerous actions
const dangerButtons = document.querySelectorAll('.btn-danger');
dangerButtons.forEach(button => {
    if (button.type !== 'submit') {
        button.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to proceed with this action?')) {
                e.preventDefault();
            }
        });
    }
});

// Show loading state on form submit
document.querySelectorAll('.setting-form').forEach(form => {
    form.addEventListener('submit', function() {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            const originalText = submitBtn.textContent;
            submitBtn.textContent = '⏳ Saving...';
            submitBtn.disabled = true;
            
            // Re-enable after 3 seconds if form doesn't redirect
            setTimeout(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            }, 3000);
        }
    });
});