// Filament's tables, adjusted where their markup falls short of WCAG 2.2 AA.
// ponytail: tied to Filament's table markup, with `tests/Browser/EntryListTest.php` as its alarm: it fails once Filament changes the move buttons, the selection checkbox or the reorder focus. Each part goes once Filament covers it.
document.addEventListener('livewire:init', () => {
    const adjust = (root) => {
        // Filament keeps the move buttons out of the Tab order (filamentphp/filament#20713): a keyboard user without a screen reader could not move a row (WCAG 2.1.1). `kami.css` shows them.
        root.querySelectorAll('[data-table-reorder-direction][tabindex]').forEach((button) => button.removeAttribute('tabindex'))

        // Filament names a selection checkbox after the record's key, a ULID for an Entry (WCAG 2.4.6): its own translated label, with the row's title in the key's place.
        root.querySelectorAll('.fi-ta-record-checkbox').forEach((checkbox) => {
            const title = checkbox.closest('tr')?.querySelector('[wire\\:key$=".column.title"] .fi-ta-text')?.textContent.trim()

            if (title && checkbox.value) {
                checkbox.setAttribute('aria-label', checkbox.getAttribute('aria-label').replace(checkbox.value, title))
            }
        })
    }

    document.addEventListener('livewire:initialized', () => adjust(document))

    // A keyboard move re-renders the rows and drops focus to the body (WCAG 2.4.3): bring it back to the moved row's control, or its other one where the row reached the end.
    let moved = null

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-table-reorder-direction]')

        if (button) {
            moved = { key: button.closest('[x-sortable-item]').getAttribute('x-sortable-item'), direction: button.dataset.tableReorderDirection }
        }
    }, true)

    Livewire.hook('morphed', ({ el }) => {
        adjust(el)

        const row = moved && el.querySelector(`[x-sortable-item="${CSS.escape(moved.key)}"]`)

        // Another component may answer first: the table's own morph takes it.
        if (! row) {
            return
        }

        const buttons = [...row.querySelectorAll('[data-table-reorder-direction]')].filter((button) => ! button.disabled)

        ;(buttons.find((button) => button.dataset.tableReorderDirection === moved.direction) ?? buttons[0])?.focus()
        moved = null
    })
})
