<?php

namespace App\Filament\Admin\Resources\LeadIntegrationResource\Pages;

use App\Filament\Admin\Resources\LeadIntegrationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateLeadIntegration extends CreateRecord
{
    protected static string $resource = LeadIntegrationResource::class;

    protected string $plainToken = '';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->plainToken = Str::random(48);

        // Only the SHA-256 hash is stored; the plaintext is shown once.
        $data['token'] = hash('sha256', $this->plainToken);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->plainToken !== '') {
            Notification::make()
                ->title('Integration token — copy it now')
                ->body($this->plainToken)
                ->persistent()
                ->success()
                ->send();
        }
    }
}
