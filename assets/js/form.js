// assets/js/form.js

var currentStep = 1;

function showStep(step) {
    // Hide all steps
    document.querySelectorAll('.form-step').forEach(function(el) {
        el.classList.remove('active');
    });
    
    // Show target step
    var targetStep = document.getElementById('step-' + step);
    if (targetStep) {
        targetStep.classList.add('active');
    }
    
    // Update step indicator classes in header
    document.querySelectorAll('.step-indicator').forEach(function(ind, idx) {
        var indStep = idx + 1;
        ind.classList.remove('active', 'completed');
        
        // Normalize indicator numbering based on steps visibility
        if (indStep === step) {
            ind.classList.add('active');
        } else if (indStep < step) {
            ind.classList.add('completed');
        }
    });
    
    currentStep = step;
}

function nextStep(step) {
    // Validate current step fields before going forward
    if (!validateStep(step)) {
        return;
    }
    
    var next = step + 1;
    
    // Handle skipped steps based on configs
    if (next === 2 && !hasStep2) {
        next = 3;
    }
    if (next === 3 && !hasStep3) {
        return;
    }
    
    showStep(next);
}

function prevStep(step) {
    var prev = step - 1;
    
    // Handle skipped steps backwards
    if (prev === 2 && !hasStep2) {
        prev = 1;
    }
    
    showStep(prev);
}

function validateStep(step) {
    var isValid = true;
    var container = document.getElementById('step-' + step);
    if (!container) return false;
    
    // Find all inputs, selects inside the current step container
    var fields = container.querySelectorAll('input:not([type="hidden"]), select');
    
    fields.forEach(function(field) {
        // Clear previous custom error messages if any
        var parent = field.closest('.form-group');
        if (!parent) return;
        
        var err = parent.querySelector('.js-error');
        if (err) err.remove();
        
        var val = field.value.trim();
        
        if (field.hasAttribute('required') && !val) {
            isValid = false;
            field.classList.add('invalid');
            
            // Add custom error message
            var labelEl = parent.querySelector('.form-label');
            var label = labelEl ? labelEl.textContent.replace('*', '').trim() : 'Field';
            
            addCustomError(parent, label + " is required.");
        } else {
            field.classList.remove('invalid');
            
            // Custom field validation checks
            if (val) {
                if (field.id === 'mobile_number') {
                    var phonePattern = /^[0-9+ ]{8,20}$/;
                    if (!phonePattern.test(val)) {
                        isValid = false;
                        addCustomError(parent, "Please enter a valid mobile number.");
                    }
                }
                if (field.id === 'jersey_name') {
                    var alphaPattern = /^[A-Z ]+$/i;
                    if (!alphaPattern.test(val)) {
                        isValid = false;
                        addCustomError(parent, "Jersey Name must contain only English letters and spaces.");
                    }
                }
                if (field.id === 'jersey_number') {
                    var num = parseInt(val, 10);
                    if (isNaN(num) || num < 0 || num > 999) {
                        isValid = false;
                        addCustomError(parent, "Jersey Number must be between 0 and 999.");
                    }
                }
                if (field.id === 'jersey_number_opt1' || field.id === 'jersey_number_opt2' || field.id === 'jersey_number_opt3') {
                    var jnum = parseInt(val, 10);
                    if (!isNaN(jnum) && (jnum < 0 || jnum > 999)) {
                        isValid = false;
                        addCustomError(parent, "Jersey number must be between 0 and 999.");
                    }
                }
            }
        }
    });
    
    // Validate combined sleeve quantity
    var halfInput  = container.querySelector('#half_sleeve_qty');
    var fullInput  = container.querySelector('#full_sleeve_qty');
    if (halfInput && fullInput) {
        var halfQty = parseInt(halfInput.value, 10) || 0;
        var fullQty = parseInt(fullInput.value, 10) || 0;
        if (halfQty + fullQty > 4) {
            isValid = false;
            var halfGroup = document.querySelector('[id="btn-minus-half_sleeve_qty"]');
            var errTarget = halfGroup ? halfGroup.closest('.form-group') : null;
            if (errTarget) {
                var existing = errTarget.querySelector('.js-error');
                if (!existing) {
                    addCustomError(errTarget, "Total playing jersey quantity (Half + Full Sleeve) cannot exceed 4.");
                }
            }
        }
    }
    
    // Validate jersey number options are unique (no duplicates)
    var jerseyOptIds = ['jersey_number_opt1', 'jersey_number_opt2', 'jersey_number_opt3'];
    var seenJerseyNums = [];
    jerseyOptIds.forEach(function(optId) {
        var optEl = container.querySelector('#' + optId);
        if (!optEl) return;
        var optVal = optEl.value.trim();
        if (optVal === '') return; // skip empty options — they are optional
        var parent = optEl.closest('.form-group');
        if (seenJerseyNums.indexOf(optVal) !== -1) {
            isValid = false;
            if (parent) {
                var dupErr = parent.querySelector('.js-error');
                if (!dupErr) {
                    addCustomError(parent, 'Duplicate jersey number. Each option must be a different number.');
                }
            }
        } else {
            seenJerseyNums.push(optVal);
        }
    });
    
    return isValid;
}

function addCustomError(parent, msgText) {
    var errMsg = document.createElement('small');
    errMsg.className = 'js-error';
    errMsg.style.color = 'var(--danger)';
    errMsg.style.fontSize = '0.8rem';
    errMsg.style.marginTop = '0.25rem';
    errMsg.style.display = 'block';
    errMsg.textContent = msgText;
    parent.appendChild(errMsg);
}

// Quantity Stepper: adjustQty(fieldId, delta)
function adjustQty(fieldId, delta) {
    var input   = document.getElementById(fieldId);
    var display = document.getElementById('display-' + fieldId);
    var btnMinus = document.getElementById('btn-minus-' + fieldId);
    var btnPlus  = document.getElementById('btn-plus-'  + fieldId);
    if (!input) return;
    
    var current = parseInt(input.value, 10) || 0;
    var next    = Math.max(0, Math.min(3, current + delta));
    
    // If increasing, check combined constraint
    if (delta > 0) {
        var otherId  = (fieldId === 'half_sleeve_qty') ? 'full_sleeve_qty' : 'half_sleeve_qty';
        var otherInput = document.getElementById(otherId);
        var otherQty = otherInput ? (parseInt(otherInput.value, 10) || 0) : 0;
        if (next + otherQty > 4) {
            next = 4 - otherQty; // cap at what combined allows
            if (next <= current) return;
        }
    }
    
    input.value   = next;
    display.textContent = next;
    
    btnMinus.disabled = (next <= 0);
    btnPlus.disabled  = (next >= 3);
    
    // Also update plus button of the other stepper if combined is now 4
    var otherId2   = (fieldId === 'half_sleeve_qty') ? 'full_sleeve_qty' : 'half_sleeve_qty';
    var otherInput2 = document.getElementById(otherId2);
    var otherPlus   = document.getElementById('btn-plus-' + otherId2);
    if (otherInput2 && otherPlus) {
        var otherCurrent = parseInt(otherInput2.value, 10) || 0;
        otherPlus.disabled = (next + otherCurrent >= 4) || (otherCurrent >= 3);
    }
}

// Automatically bind key listeners on load
document.addEventListener('DOMContentLoaded', function() {
    var jerseyNameInput = document.getElementById('jersey_name');
    if (jerseyNameInput) {
        jerseyNameInput.addEventListener('input', function() {
            var val = this.value.toUpperCase();
            this.value = val.replace(/[^A-Z ]/g, ''); // Enforce UPPERCASE character validation rules instantly
        });
    }
    
    var form = document.getElementById('kitForm');
    if (form) {
        // Prevent Enter key from submitting form, handle with stepper Continue
        form.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                if (e.target.tagName.toLowerCase() === 'textarea') {
                    return; // allow newline in textareas
                }
                e.preventDefault();
                
                // Determine if there is a next step
                var hasNext = false;
                if (currentStep === 1) {
                    if (hasStep2 || hasStep3) hasNext = true;
                } else if (currentStep === 2) {
                    if (hasStep3) hasNext = true;
                }
                
                if (hasNext) {
                    nextStep(currentStep);
                } else {
                    // Final step: trigger form submit validation by clicking submit button
                    var submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.click();
                    }
                }
            }
        });

        form.addEventListener('submit', function(e) {
            // Validate the active step prior to post submission
            if (!validateStep(currentStep)) {
                e.preventDefault();
                return;
            }
            
            // Prevent multiple click submission loops
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = "Submitting details...";
            }
        });
    }
});

