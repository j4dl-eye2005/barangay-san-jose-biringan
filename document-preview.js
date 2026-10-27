document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('print-document')?.addEventListener('click', () => window.print());
    const recordButton = document.getElementById('record-sample-issue');
    recordButton?.addEventListener('click', async () => {
        if (!window.confirm('Record that an authorized staff member reviewed and prepared this watermarked sample?')) return;
        recordButton.disabled = true;
        const feedback = document.getElementById('issue-feedback');
        if (feedback) feedback.textContent = 'Saving the review record…';
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
            recordButton.textContent = 'Sample review recorded';
            recordButton.disabled = true;
            if (feedback) feedback.textContent = `Recorded ${result.document_number}. The printed sample remains watermarked.`;
        } catch (error) {
            recordButton.disabled = false;
            if (feedback) feedback.textContent = error.message || 'The review record could not be saved.';
        }
    });
});
