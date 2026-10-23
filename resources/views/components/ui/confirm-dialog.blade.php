{{-- Replaces the browser's confirm() for every wire:confirm (see resources/js/app.js). --}}
<div
    x-data="{
        open: false,
        title: '',
        body: '',
        button: 'Confirmer',
        danger: false,
        action: null,
        instead: null,
        show(detail) {
            const message = detail.message.trim();
            const end = message.indexOf('?');
            const verb = message.split(/\s+/)[0];

            // « Supprimer X ? Les soldes seront recalculés. » → title « Supprimer X ? », button « Supprimer ».
            this.title = end === -1 ? message : message.slice(0, end + 1);
            this.body = end === -1 ? '' : message.slice(end + 1).trim();
            this.button = detail.button ?? (verb.endsWith('er') ? verb : 'Confirmer');
            this.danger = ['Supprimer', 'Retirer', 'Désactiver', 'Suspendre'].includes(this.button);
            this.action = detail.action;
            this.instead = detail.instead;
            this.open = true;
            this.$nextTick(() => this.$refs.confirm.focus());
        },
        close(confirmed) {
            if (! this.open) return;
            this.open = false;
            (confirmed ? this.action : this.instead)?.();
            this.action = this.instead = null;
        },
    }"
    x-on:confirm-dialog.window="show($event.detail)"
    x-on:keydown.escape.window="close(false)"
>
    <div
        x-cloak
        x-show="open"
        class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-dialog-title"
        aria-describedby="confirm-dialog-body"
    >
        <div
            x-show="open"
            x-transition:enter="duration-200 ease-out"
            x-transition:enter-start="opacity-0"
            x-transition:leave="duration-150 ease-in"
            x-transition:leave-end="opacity-0"
            x-on:click="close(false)"
            class="absolute inset-0 bg-zinc-950/40 backdrop-blur-[2px]"
        ></div>

        <div
            x-show="open"
            x-trap.noscroll="open"
            x-transition:enter="duration-200 ease-out"
            x-transition:enter-start="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
            x-transition:leave="duration-150 ease-in"
            x-transition:leave-end="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
            class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl shadow-zinc-950/20 ring-1 ring-zinc-900/5"
        >
            <div class="flex gap-4 p-6">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-full"
                    x-bind:class="danger ? 'bg-red-50 text-red-600 ring-8 ring-red-50/50' : 'bg-brand-50 text-brand-600 ring-8 ring-brand-50/50'"
                >
                    <x-ui.icon name="warning" class="size-5" x-show="danger" />
                    <x-ui.icon name="check" class="size-5" x-show="! danger" />
                </span>
                <div class="min-w-0 pt-1">
                    <h2 id="confirm-dialog-title" class="text-base font-semibold text-zinc-950" x-text="title"></h2>
                    <p id="confirm-dialog-body" class="mt-1.5 text-sm leading-relaxed text-zinc-600" x-show="body" x-text="body"></p>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-zinc-100 bg-zinc-50/80 px-6 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    x-on:click="close(false)"
                    class="inline-flex items-center justify-center rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 shadow-xs ring-1 ring-zinc-300 ring-inset transition hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                >Annuler</button>
                <button
                    type="button"
                    x-ref="confirm"
                    x-on:click="close(true)"
                    x-text="button"
                    class="inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition focus-visible:outline-2 focus-visible:outline-offset-2"
                    x-bind:class="danger ? 'bg-red-600 hover:bg-red-700 focus-visible:outline-red-500' : 'bg-brand-600 hover:bg-brand-700 focus-visible:outline-brand-500'"
                ></button>
            </div>
        </div>
    </div>
</div>
