document.addEventListener('DOMContentLoaded', function () {

    // --- Message Handling ---
    const messagesContainer = document.getElementById('messages-container');
    if (messagesContainer) {
        const messages = messagesContainer.querySelectorAll('.message');
        messages.forEach(msg => {
            // Auto-hide non-persistent messages
            if (!msg.classList.contains('persistent')) {
                setTimeout(() => { hideMessage(msg); }, 7000);
            }
            // Add close button functionality
            const closeButton = msg.querySelector('.close-message');
            if (closeButton) {
                closeButton.addEventListener('click', () => hideMessage(msg));
            }
        });
    }

    function hideMessage(msgElement) {
        if (msgElement) {
            msgElement.style.opacity = '0';
            // Use transitionend event to add 'hidden' class after fade out
            msgElement.addEventListener('transitionend', () => {
                 msgElement.classList.add('hidden');
                 // Optional: remove element from DOM after hiding if needed
                 // msgElement.remove();
            }, { once: true }); // Ensure the event listener runs only once
        }
    }

    // --- Table Sorting ---
    const sortableTable = document.querySelector('table.sortable-table');
    if (sortableTable) {
        const headers = sortableTable.querySelectorAll('th.sortable');
        const tableBody = sortableTable.querySelector('tbody');
        const rows = Array.from(tableBody.querySelectorAll('tr'));

        headers.forEach((header, index) => {
            header.addEventListener('click', () => {
                // Remove sorting classes from other headers
                headers.forEach(h => { if (h !== header) h.classList.remove('asc', 'desc'); });

                // Determine sort direction
                let direction = header.classList.contains('asc') ? 'desc' : 'asc';
                header.classList.remove('asc', 'desc'); // Reset current header classes
                header.classList.add(direction); // Add new direction class

                // Sort rows
                rows.sort((rowA, rowB) => {
                    // Get cell content, handle potential missing cells gracefully
                    const cellAContent = rowA.querySelectorAll('td')[index]?.textContent?.trim().toLowerCase() ?? '';
                    const cellBContent = rowB.querySelectorAll('td')[index]?.textContent?.trim().toLowerCase() ?? '';

                    // Attempt numeric sort first if applicable
                    const numA = parseFloat(cellAContent);
                    const numB = parseFloat(cellBContent);

                    if (!isNaN(numA) && !isNaN(numB)) {
                        // Both are numbers
                        if (numA < numB) return direction === 'asc' ? -1 : 1;
                        if (numA > numB) return direction === 'asc' ? 1 : -1;
                        return 0;
                    } else {
                        // Fallback to alphanumeric sort
                        if (cellAContent < cellBContent) return direction === 'asc' ? -1 : 1;
                        if (cellAContent > cellBContent) return direction === 'asc' ? 1 : -1;
                        return 0; // Rows are equal in this column
                    }
                });

                // Re-append sorted rows to the table body
                rows.forEach(row => tableBody.appendChild(row));
            });
        });
    }

    // --- License Test Button & Copy ---
    document.addEventListener('click', function(event) {
        // Test Button Click
        if (event.target.classList.contains('btn-test')) {
            const button = event.target;
            const domain = button.dataset.domain;
            const key = button.dataset.key;
            const token = button.dataset.token;
            const actionCell = button.closest('.test-action'); // Find the parent cell
            if (!actionCell) return;

            const resultDisplay = actionCell.querySelector('.test-result-display');
            const copyButton = actionCell.querySelector('.btn-copy');

            if (!domain || !key || !token || !resultDisplay || !copyButton) {
                console.error('Error: Missing required elements or data attributes for license test.');
                return;
            }

            // Reset display and button state
            resultDisplay.innerHTML = '<span>Loading test result...</span>';
            resultDisplay.className = 'test-result-display loading visible'; // Show loading state
            copyButton.style.display = 'none'; // Hide copy button initially
            // **NEW:** Clear previous response stored on the action cell
            delete actionCell.dataset.lastResponse;

            // Disable ALL test buttons within the same cell during test
            actionCell.querySelectorAll('.btn-test').forEach(btn => {
                btn.disabled = true;
                btn.style.cursor = 'wait';
                if (btn === button) { // Only change text of the clicked button
                    btn.textContent = 'Testing...';
                }
            });

            // Prepare form data
            const formData = new FormData();
            formData.append('domain', domain);
            formData.append('key', key);
            formData.append('token', token);

            let rawResponseText = ''; // To store the raw JSON response

            // Fetch request to license-check.php
            fetch('license-check.php', { method: 'POST', body: formData })
            .then(response => {
                const responseClone = response.clone(); // Clone to read text and check status
                // Check response status and content type, get text data
                return Promise.all([response.ok, response.headers.get("content-type"), responseClone.text(), response]);
            })
            .then(([isOk, contentType, textData, originalResponse]) => {
                rawResponseText = textData; // Store raw text
                if (!isOk) throw new Error(`HTTP error ${originalResponse.status}: ${textData || originalResponse.statusText}`);
                if (!contentType || contentType.indexOf("application/json") === -1) throw new Error(`Expected JSON response, but received non-JSON content:\n${textData}`);

                // Try parsing the JSON
                try {
                    const jsonData = JSON.parse(textData);
                    // Build HTML for display
                    let displayHtml = `<div class="result-status result-status-${jsonData.status || 'unknown'}">Status: <strong>${(jsonData.status || 'Unknown').toUpperCase()}</strong></div>`;
                    displayHtml += `<div class="result-message">${jsonData.message || ''}</div>`;
                    displayHtml += '<hr>';
                    displayHtml += '<div class="result-details">';
                    // Loop through JSON data properties for details
                    for (const key in jsonData) {
                        if (key !== 'status' && key !== 'message' && Object.prototype.hasOwnProperty.call(jsonData, key)) {
                            const formattedKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()); // Format key nicely
                            displayHtml += `<div><strong>${formattedKey}:</strong> ${jsonData[key] !== null ? jsonData[key] : 'N/A'}</div>`;
                        }
                    }
                    displayHtml += '</div>';

                    // Update display area
                    resultDisplay.innerHTML = displayHtml;
                    resultDisplay.className = `test-result-display visible result-${jsonData.status || 'unknown'}`; // Add status class for styling
                    // **NEW:** Store JSON string on the PARENT action cell using dataset
                    actionCell.dataset.lastResponse = rawResponseText;
                    copyButton.style.display = 'inline-block'; // Show copy button
                } catch (e) {
                    // Handle JSON parsing error
                    throw new Error(`Failed to parse JSON response: ${e.message}\nRaw Response:\n${textData}`);
                }
            })
            .catch(error => {
                // Handle fetch or other errors
                console.error('Error testing license check:', error);
                resultDisplay.innerHTML = `<strong>Error:</strong><br>${error.message.replace(/\n/g, '<br>')}`; // Display error message
                resultDisplay.className = 'test-result-display error visible'; // Style as error
                copyButton.style.display = 'none'; // Hide copy button on error
            })
            .finally(() => {
                // Reset ALL test buttons state within the same cell
                 actionCell.querySelectorAll('.btn-test').forEach(btn => {
                    btn.disabled = false;
                    // Determine button text based on domain type (Primary/Secondary)
                    const isPrimary = btn.title.toLowerCase().includes('primary');
                    btn.textContent = isPrimary ? '🧪 P' : '🧪 S';
                    btn.style.cursor = 'pointer';
                });
            });
        }

        // Copy Button Click
        if (event.target.classList.contains('btn-copy')) {
            const copyButton = event.target;
            const actionCell = copyButton.closest('.test-action');
            if (!actionCell) return;

            // **NEW:** Check if there's a stored JSON response on the action cell's dataset
            if (actionCell.dataset.lastResponse) {
                navigator.clipboard.writeText(actionCell.dataset.lastResponse)
                    .then(() => {
                        // Provide visual feedback on successful copy
                        const originalText = copyButton.textContent;
                        copyButton.textContent = '✅ Copied!';
                        copyButton.disabled = true; // Briefly disable after copy
                        setTimeout(() => {
                            copyButton.textContent = originalText;
                            copyButton.disabled = false;
                         }, 1500); // Reset text and enable after 1.5s
                    })
                    .catch(err => {
                        // Handle clipboard write error
                        console.error('Failed to copy JSON response:', err);
                        alert('Failed to copy JSON response. See browser console for details.');
                    });
            } else {
                // Alert if no response is available
                alert('No JSON response available to copy. Please run the test first.');
            }
        }
    });

    // --- Add/Edit License Form: Toggle Expires At based on License Type ---
    const licenseTypeSelect = document.getElementById('license_type');
    const expiresAtInput = document.getElementById('expires_at');
    if (licenseTypeSelect && expiresAtInput) {
        function toggleExpiresAt() {
            const isLifetime = (licenseTypeSelect.value === 'Lifetime');
            expiresAtInput.disabled = isLifetime;
            // Clear the date input if Lifetime is selected
            if (isLifetime) {
                expiresAtInput.value = '';
            }
        }
        // Add event listener and call initially to set the correct state
        licenseTypeSelect.addEventListener('change', toggleExpiresAt);
        toggleExpiresAt(); // Run on page load
    }

    // --- Chart Initialization (Moved to dashboard.php inline script) ---
    // Chart.js initialization code that depends on PHP variables is now
    // directly in dashboard.php after the PHP data fetching.

});
