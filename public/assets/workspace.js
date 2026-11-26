'use strict';

for (const slider of document.querySelectorAll('input[type="range"]')) {
    const output = slider.parentElement.querySelector('output');
    if (output) {
        slider.addEventListener('input', () => { output.value = slider.value; });
    }
}

for (const group of document.querySelectorAll('[data-line-group]')) {
    const add = group.querySelector('[data-add-line]');
    const template = group.querySelector('[data-line]').cloneNode(true);
    let next = 1;

    function updateRemoveButtons() {
        const lines = group.querySelectorAll('[data-remove-line]');
        for (const button of lines) {
            button.hidden = lines.length === 1;
        }
    }

    add.hidden = false;
    add.addEventListener('click', () => {
        const line = template.cloneNode(true);
        for (const field of line.querySelectorAll('[name]')) {
            field.name = field.name.replace('[0]', '[' + next + ']');
        }
        next += 1;
        add.before(line);
        updateRemoveButtons();
        line.querySelector('[name]').focus();
    });

    group.addEventListener('click', (event) => {
        if (event.target.matches('[data-remove-line]') && group.querySelectorAll('[data-line]').length > 1) {
            event.target.closest('[data-line]').remove();
            updateRemoveButtons();
            add.focus();
        }
    });
}
