<div>
    @if ($show)
        <label class="ind-switch" title="Show one industry, or all">
            <span class="ind-switch-label">Industry</span>
            <select wire:model.live="industry" aria-label="Industry shown">
                <option value="">All industries</option>
                @foreach ($options as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <style>
            .ind-switch { display: flex; align-items: center; gap: 6px; font-size: .875rem; margin-inline: 8px; }
            .ind-switch-label { opacity: .7; }
            .ind-switch select { border-radius: 8px; padding: 4px 28px 4px 10px; font-size: .875rem; background-color: transparent; border: 1px solid rgb(0 0 0 / .15); }
            .dark .ind-switch select { border-color: rgb(255 255 255 / .2); color: inherit; }
            .dark .ind-switch select option { color: #111827; }
        </style>
    @endif
</div>
