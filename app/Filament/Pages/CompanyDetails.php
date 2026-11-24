<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

/** Settings: our company details, logo, signature and invoice numbering. The pictures are kept in the database, since the host's disk is wiped on restart. */
class CompanyDetails extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Company details';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['logo' => null, 'signature' => null] + Setting::allValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Our company')->columns(2)->schema([
                TextInput::make('company_name')->required()->maxLength(255),
                TextInput::make('company_gstin')->label('GSTIN')->maxLength(15)->regex('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'),
                Textarea::make('company_address')->required()->helperText('Include the 6-digit PIN code; the e-invoice needs it.')->columnSpanFull(),
                TextInput::make('company_email')->email(),
                TextInput::make('company_phone'),
                TextInput::make('lut_arn')->label('LUT ARN')->helperText('Printed on export invoices.'),
                TextInput::make('signatory')->label('Signatory (For …)')->placeholder('Defaults to the company name'),
                FileUpload::make('logo')->image()->disk('local')->directory('branding')->maxSize(1024)
                    ->helperText(fn () => Setting::get('logo') ? 'A logo is saved. Upload another to replace it.' : 'PNG or JPG, up to 1 MB.'),
                FileUpload::make('signature')->image()->disk('local')->directory('branding')->maxSize(1024)
                    ->helperText(fn () => Setting::get('signature') ? 'A signature is saved. Upload another to replace it.' : 'PNG or JPG, up to 1 MB.'),
            ]),
            Section::make('Invoices')->columns(4)->schema([
                TextInput::make('proforma_prefix')->label('Proforma prefix')->required()->alphaNum()->maxLength(10),
                TextInput::make('tax_prefix')->label('Tax invoice prefix')->required()->alphaNum()->maxLength(10)->different('proforma_prefix'),
                TextInput::make('gst_rate')->label('GST rate %')->numeric()->minValue(0)->maxValue(28)->required(),
                TextInput::make('payment_days')->label('Payment due in (days)')->integer()->minValue(0)->required(),
                Textarea::make('terms')->label('Terms & conditions')->rows(4)->columnSpanFull(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $values = $this->form->getState();
        $values['company_gstin'] = strtoupper(trim((string) ($values['company_gstin'] ?? '')));
        foreach (['logo', 'signature'] as $image) {
            $path = $values[$image] ?? null;
            unset($values[$image]);
            if (filled($path) && Storage::disk('local')->exists($path)) {
                $values[$image] = 'data:'.Storage::disk('local')->mimeType($path).';base64,'.base64_encode(Storage::disk('local')->get($path));
                Storage::disk('local')->delete($path);
            }
        }
        Setting::put(array_map(fn ($value) => (string) ($value ?? ''), $values));
        $this->form->fill(['logo' => null, 'signature' => null] + Setting::allValues());
        Notification::make()->title('Saved')->success()->send();
    }
}
