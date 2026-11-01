// A keyboard move re-renders the rows and drops focus to the body (WCAG 2.4.3): bring it back to the moved row's control, or its other one where the row reached the end.
// ponytail: tied to Filament's reorder markup, with `tests/Browser/EntryListTest.php` as its alarm; goes when Filament holds focus itself.
document.addEventListener('livewire:init', () => {
    let moved = null

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-table-reorder-direction]')

        if (button) {
            moved = { key: button.closest('[x-sortable-item]').getAttribute('x-sortable-item'), direction: button.dataset.tableReorderDirection }
        }
    }, true)

    Livewire.hook('morphed', () => {
        const { key, direction } = moved ?? {}
        moved = null

        if (! key) {
            return
        }

        const row = document.querySelector(`[x-sortable-item="${CSS.escape(key)}"]`)
        const buttons = [...(row?.querySelectorAll('[data-table-reorder-direction]') ?? [])].filter((button) => ! button.disabled)

        ;(buttons.find((button) => button.dataset.tableReorderDirection === direction) ?? buttons[0])?.focus()
    })
})
