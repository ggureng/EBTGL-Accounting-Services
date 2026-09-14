<?php
// This file contains only the bookkeeping form and its functions
// It's designed to be included in asset.php
?>

<!-- Bookkeeping Form -->
<div class="card">
    <div class="card-header">
        <h3 class="mb-0" style="font-size: 1.2rem;"><i class="fas fa-book me-2"></i> Record Transaction</h3>
    </div>
    <div class="card-body" style="position: relative;">
        <?php if ($is_bankrupt && !$accessGranted): ?>
        <div class="form-disabled-overlay">
            <div class="form-disabled-message">
                <i class="fas fa-ban me-2"></i>
                Transaction capabilities disabled due to bankruptcy proceedings
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error_message) ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            Transaction recorded successfully!
        </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="transactionForm" onsubmit="return validateForm()">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="date" class="form-label">Date <span class="required-asterisk">*</span></label>
                    <input type="date" class="form-control" id="date" name="date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="description" class="form-label">Description <span class="required-asterisk">*</span></label>
                    <input type="text" class="form-control" id="description" name="description" placeholder="Enter transaction description" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Document Attachment</label>
                    <input type="file" class="form-control" id="document" name="document[]">
                </div>
            </div>
            
            <!-- Journal Entries -->
            <div class="section-title">Journal Entries</div>
            
            <div id="entries-container">
                <!-- Entry rows will be added here dynamically -->
                <div class="entry-row">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">Account <span class="required-asterisk">*</span></label>
                            <select class="form-select" name="entries[0][account_id]" required>
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $account): ?>
                                <option value="<?= $account['account_number'] ?>">
                                    <?= htmlspecialchars($account['name']) ?> (<?= $account['type'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Entry Type <span class="required-asterisk">*</span></label>
                            <select class="form-select" name="entries[0][type]" required>
                                <option value="debit">Debit</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Amount <span class="required-asterisk">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="text" class="form-control amount-input" name="entries[0][amount]" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <div class="entry-controls">
                                <button type="button" class="btn btn-outline-secondary" onclick="removeEntry(this)" title="Remove Entry">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between mt-3">
                <button type="button" class="btn btn-primary" onclick="addEntry()">
                    <i class="fas fa-plus me-1"></i> Add Entry
                </button>
                
                <div>
                    <button type="button" class="btn btn-outline-secondary me-2" onclick="clearEntry()">
                        <i class="fas fa-eraser me-1"></i> Clear All
                    </button>
                    <button type="submit" class="btn btn-primary" <?= ($is_bankrupt && !$accessGranted) ? 'disabled' : '' ?>>
                        <i class="fas fa-save me-1"></i> Record Transaction
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Bookkeeping form functionality
let entryCount = 1;
let currentCurrency = {
    code: 'PHP',
    symbol: '₱',
    rate: 1
};

// Initialize form when included
function initializeBookkeepingForm() {
    console.log('Initializing bookkeeping form...');
    
    // Set up amount input formatting
    document.querySelectorAll('.amount-input').forEach(input => {
        input.addEventListener('input', handleAmountInput);
        input.addEventListener('blur', formatAmount);
    });
    
    // Set up currency dropdown
    initializeCurrencyDropdown();
    
    // Initialize form validation
    initializeForm();
}

// Handle amount input with comma formatting
function handleAmountInput(event) {
    let value = event.target.value.replace(/[^\d.]/g, '');
    
    // Remove existing commas for processing
    value = value.replace(/,/g, '');
    
    // Format with commas
    if (value) {
        const parts = value.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        event.target.value = parts.join('.');
    }
}

// Format amount on blur
function formatAmount(event) {
    let value = event.target.value.replace(/[^\d.]/g, '');
    
    if (value) {
        const num = parseFloat(value);
        if (!isNaN(num)) {
            const parts = num.toFixed(2).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            event.target.value = parts.join('.');
        }
    }
}

// Change currency
function changeCurrency(currencyCode, symbol, rate) {
    currentCurrency.code = currencyCode;
    currentCurrency.symbol = symbol;
    currentCurrency.rate = rate;
    
    // Update all currency symbols
    document.querySelectorAll('.input-group-text').forEach(span => {
        span.textContent = symbol;
    });
    
    // Update displayed amounts if needed
    // Note: In a real application, you would convert amounts here
}

// Initialize currency dropdown
function initializeCurrencyDropdown() {
    const currencies = [
        {code: 'PHP', symbol: '₱', rate: 1},
        {code: 'USD', symbol: '$', rate: 56.50},
        {code: 'EUR', symbol: '€', rate: 61.20},
        {code: 'JPY', symbol: '¥', rate: 0.38}
    ];
    
    // This would be connected to a currency dropdown UI element
    // For now, we'll just set the default
    changeCurrency('PHP', '₱', 1);
}

// Add new entry row
function addEntry() {
    const container = document.getElementById('entries-container');
    const newEntry = document.createElement('div');
    newEntry.className = 'entry-row';
    newEntry.innerHTML = `
        <div class="row">
            <div class="col-md-4">
                <label class="form-label">Account <span class="required-asterisk">*</span></label>
                <select class="form-select" name="entries[${entryCount}][account_id]" required>
                    <option value="">Select Account</option>
                    <?php foreach ($accounts as $account): ?>
                    <option value="<?= $account['account_number'] ?>">
                        <?= htmlspecialchars($account['name']) ?> (<?= $account['type'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Entry Type <span class="required-asterisk">*</span></label>
                <select class="form-select" name="entries[${entryCount}][type]" required>
                    <option value="debit">Debit</option>
                    <option value="credit">Credit</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Amount <span class="required-asterisk">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">${currentCurrency.symbol}</span>
                    <input type="text" class="form-control amount-input" name="entries[${entryCount}][amount]" placeholder="0.00" required>
                </div>
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <div class="entry-controls">
                    <button type="button" class="btn btn-outline-secondary" onclick="removeEntry(this)" title="Remove Entry">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(newEntry);
    
    // Set up event listeners for the new amount input
    const amountInput = newEntry.querySelector('.amount-input');
    amountInput.addEventListener('input', handleAmountInput);
    amountInput.addEventListener('blur', formatAmount);
    
    entryCount++;
}

// Remove entry row
function removeEntry(button) {
    const entryRow = button.closest('.entry-row');
    if (document.querySelectorAll('.entry-row').length > 1) {
        entryRow.remove();
    } else {
        alert('At least one entry is required.');
    }
}

// Clear all entries
function clearEntry() {
    if (confirm('Are you sure you want to clear all entries? This cannot be undone.')) {
        const container = document.getElementById('entries-container');
        container.innerHTML = '';
        
        // Add one empty entry
        entryCount = 0;
        addEntry();
        
        // Clear other form fields
        document.getElementById('description').value = '';
        document.getElementById('document').value = '';
    }
}

// Validate form before submission
function validateForm() {
    const entries = document.querySelectorAll('.entry-row');
    let totalDebit = 0;
    let totalCredit = 0;
    let hasErrors = false;
    
    // Clear previous validation
    document.querySelectorAll('.is-invalid').forEach(el => {
        el.classList.remove('is-invalid');
    });
    
    document.querySelectorAll('.invalid-feedback-custom').forEach(el => {
        el.remove();
    });
    
    // Validate each entry
    entries.forEach((entry, index) => {
        const accountSelect = entry.querySelector('select[name^="entries"]');
        const amountInput = entry.querySelector('input[name^="entries"][name$="[amount]"]');
        const entryType = entry.querySelector('select[name$="[type]"]');
        
        let entryHasError = false;
        
        // Validate account
        if (!accountSelect.value) {
            showFieldError(accountSelect, 'Please select an account');
            entryHasError = true;
        }
        
        // Validate amount
        if (!amountInput.value) {
            showFieldError(amountInput, 'Please enter an amount');
            entryHasError = true;
        } else {
            const amount = parseFloat(amountInput.value.replace(/,/g, ''));
            if (isNaN(amount) || amount <= 0) {
                showFieldError(amountInput, 'Please enter a valid positive amount');
                entryHasError = true;
            } else {
                // Add to totals for balancing check
                if (entryType.value === 'debit') {
                    totalDebit += amount;
                } else {
                    totalCredit += amount;
                }
            }
        }
        
        if (entryHasError) {
            hasErrors = true;
        }
    });
    
    // Check if debits equal credits
    if (Math.abs(totalDebit - totalCredit) > 0.01) {
        showNotification('Debits and credits must balance. Current difference: ' + 
                        currentCurrency.symbol + Math.abs(totalDebit - totalCredit).toFixed(2), 'error');
        hasErrors = true;
    }
    
    // Validate required fields
    const requiredFields = ['date', 'description'];
    requiredFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (!field.value.trim()) {
            showFieldError(field, 'This field is required');
            hasErrors = true;
        }
    });
    
    if (hasErrors) {
        showNotification('Please fix the errors in the form before submitting.', 'error');
        return false;
    }
    
    return true;
}

// Show field error
function showFieldError(field, message) {
    field.classList.add('is-invalid');
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'invalid-feedback-custom';
    errorDiv.textContent = message;
    
    field.parentNode.appendChild(errorDiv);
    errorDiv.style.display = 'block';
}

// Initialize form
function initializeForm() {
    // Set up form validation
    const form = document.getElementById('transactionForm');
    if (form) {
        form.addEventListener('submit', function(event) {
            if (!validateForm()) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    }
    
    // Set today's date as default if not already set
    const dateField = document.getElementById('date');
    if (dateField && !dateField.value) {
        dateField.value = new Date().toISOString().split('T')[0];
    }
}

// Show notification
function showNotification(message, type = 'success') {
    // Create notification element if it doesn't exist
    let notification = document.getElementById('bookkeepingNotification');
    if (!notification) {
        notification = document.createElement('div');
        notification.id = 'bookkeepingNotification';
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            z-index: 10000;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        `;
        document.body.appendChild(notification);
    }
    
    notification.textContent = message;
    notification.style.backgroundColor = type === 'success' ? '#28a745' : '#dc3545';
    notification.style.display = 'block';
    
    setTimeout(() => {
        notification.style.display = 'none';
    }, 5000);
}

// Initialize the form when the script loads
document.addEventListener('DOMContentLoaded', function() {
    initializeBookkeepingForm();
});

// Also initialize if this file is included after DOM is loaded
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(initializeBookkeepingForm, 100);
}
</script>