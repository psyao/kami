// Submits each Kami Form on the page without a reload, through the Form route's JSON answers.
// Loaded from the site's origin with `defer`: no inline script and no eval, so the front's CSP holds.
for (const form of document.querySelectorAll('form.kami-form')) {
    const status = document.getElementById(`${form.id}-status`);
    const button = form.querySelector('button:not([type]), [type="submit"]');
    // Without its status element or a button, the form posts and the page reloads, as without this script.
    if (!status || !button) {
        continue;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(form);
        status.textContent = '';
        button.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
            });
            // The status decides: a 200 whose body cannot be read is still a Submission stored, never one to send again.
            const body = await response.json().catch(() => ({}));

            if (response.status === 200) {
                form.hidden = true;
                status.textContent = body.message ?? '';
                status.focus();
            } else if (body.errors) {
                showErrors(form, body.errors);
            } else {
                status.textContent = form.dataset.error;
            }
        } catch {
            status.textContent = form.dataset.error;
        } finally {
            button.disabled = false;
        }
    });
}

// A summary that takes the focus and links to each field, and each field's message beside it.
function showErrors(form, errors) {
    const summary = document.createElement('div');
    summary.className = 'kami-form-errors';
    summary.setAttribute('role', 'alert');
    summary.tabIndex = -1;
    const heading = summary.appendChild(document.createElement('h2'));
    heading.className = 'kami-form-errors-heading';
    heading.textContent = form.dataset.errorsHeading;
    const list = summary.appendChild(document.createElement('ul'));

    for (const [name, [message]] of Object.entries(errors)) {
        const link = list.appendChild(document.createElement('li')).appendChild(document.createElement('a'));
        link.href = `#${name}`;
        link.textContent = message;

        const field = form.elements.namedItem(name);
        if (field instanceof Element) {
            const error = document.createElement('span');
            error.id = `${name}-error`;
            error.textContent = message;
            field.after(error);
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
        }
    }

    form.prepend(summary);
    summary.focus();
}

// Removes the errors of an earlier attempt, the page's own included.
function clearErrors(form) {
    form.querySelector('.kami-form-errors')?.remove();

    for (const field of form.querySelectorAll('[aria-invalid]')) {
        const id = `${field.name}-error`;
        const describedBy = (field.getAttribute('aria-describedby') ?? '').split(' ').filter((token) => token !== id).join(' ');
        field.removeAttribute('aria-invalid');
        describedBy ? field.setAttribute('aria-describedby', describedBy) : field.removeAttribute('aria-describedby');
        document.getElementById(id)?.remove();
    }
}
