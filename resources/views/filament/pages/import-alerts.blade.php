<x-filament-panels::page>
    <div class="space-y-8">
        <select wire:model.live="periodId" class="fi-input block w-full max-w-md rounded-lg border-gray-300 mb-6">
            @foreach ($this->periods() as $period)
                <option value="{{ $period->id }}">{{ $period->name }}</option>
            @endforeach
        </select>

        <div class="grid gap-8 md:grid-cols-2 xl:gap-10">
            <x-filament::section heading="Empleados Hubstaff sin mapeo">
                <ul class="space-y-2 text-sm leading-6">
                    @forelse ($this->unmappedMembers() as $member)
                        <li>{{ $member }}</li>
                    @empty
                        <li>Sin alertas.</li>
                    @endforelse
                </ul>
            </x-filament::section>

            <x-filament::section heading="Días con horas pagables menores a las esperadas">
                <ul class="space-y-2 text-sm leading-6">
                    @forelse ($this->shortPayableDays() as $review)
                        <li>
                            <a class="text-primary-600 hover:underline" href="{{ $this->reviewUrl($review) }}">
                                {{ $review->date->toDateString() }} · {{ $review->employee?->name }} · {{ round($review->payable_seconds / 3600, 2) }}/{{ round(($review->expected_seconds + $review->assigned_overtime_seconds) / 3600, 2) }} h
                            </a>
                        </li>
                    @empty
                        <li>Sin alertas.</li>
                    @endforelse
                </ul>
            </x-filament::section>

            <x-filament::section heading="Días con idle mayor a 30 minutos">
                <ul class="space-y-2 text-sm leading-6">
                    @forelse ($this->highIdleDays() as $review)
                        <li>
                            <a class="text-primary-600 hover:underline" href="{{ $this->reviewUrl($review) }}">
                                {{ $review->date->toDateString() }} · {{ $review->employee?->name }} · {{ round($review->hubstaff_idle_seconds / 3600, 2) }} h
                            </a>
                        </li>
                    @empty
                        <li>Sin alertas.</li>
                    @endforelse
                </ul>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
