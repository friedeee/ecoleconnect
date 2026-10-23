/**
 * Shows wire:confirm questions in the application's own dialog instead of the browser's confirm().
 *
 * Livewire keeps the question on the element as `__livewire_confirm(action, instead)`; this hook runs
 * after Livewire's own and replaces it with an event the dialog in the layout listens to.
 */
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('directive.init', ({ el, directive }) => {
        if (directive.value !== 'confirm' || directive.modifiers.includes('prompt')) {
            return;
        }

        el.__livewire_confirm = (action, instead) => {
            window.dispatchEvent(
                new CustomEvent('confirm-dialog', {
                    detail: {
                        // Read at click time: the question may have changed since (Désactiver → Réactiver).
                        message: (el.getAttribute(directive.rawName) ?? directive.expression).replaceAll('\\n', '\n'),
                        button: el.dataset.confirmButton ?? null,
                        action,
                        instead,
                    },
                }),
            );
        };
    });
});
