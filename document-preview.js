document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('print-document')?.addEventListener('click', () => window.print());
    const recordButton = document.getElementById('record-sample-issue');
    recordButton?.addEventListener('click', async () => {
        const isBusiness = recordButton.dataset.business === 'true';
        const confirmation = isBusiness
            ? 'Record today as the issue date for this business clearance?'
            : 'Record that an authorized staff member reviewed and prepared this watermarked sample?';
        if (!window.confirm(confirmation)) return;
        recordButton.disabled = true;
        const feedback = document.getElementById('issue-feedback');
        if (feedback) feedback.textContent = isBusiness ? 'Saving the issue date…' : 'Saving the review record…';
        try {
            const response = await fetch('admin_issue_document.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ request_id: recordButton.dataset.requestId })
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'The review record could not be saved.');
            const number = document.getElementById('document-number');
            if (number) number.textContent = result.document_number;
            if (isBusiness) {
                const date = document.getElementById('issued-date-value');
                const year = document.getElementById('business-year-value');
                const validity = document.getElementById('valid-until-value');
                if (date && result.issued_date) date.textContent = result.issued_date;
                if (year && result.issued_year) year.textContent = result.issued_year;
                if (validity && result.issued_year) validity.textContent = `December 31, ${result.issued_year}`;
            }
            recordButton.textContent = isBusiness ? 'Issue date recorded' : 'Sample review recorded';
            recordButton.disabled = true;
            if (feedback) feedback.textContent = isBusiness
                ? `Issue date saved as ${result.issued_date}. Permit no. ${result.document_number}.`
                : `Recorded ${result.document_number}. The printed sample remains watermarked.`;
        } catch (error) {
            recordButton.disabled = false;
            if (feedback) feedback.textContent = error.message || 'The review record could not be saved.';
        }
    });
});
