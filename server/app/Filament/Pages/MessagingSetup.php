<?php

namespace App\Filament\Pages;

use App\Domain\Messaging\Gateways;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\ProviderSetup;
use App\Filament\Support\Erp;
use App\Models\MessageProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** How SMS, WhatsApp and e-mail leave this server: the client's own provider accounts. */
class MessagingSetup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Messaging';

    protected static ?string $title = 'Messaging: SMS, WhatsApp and e-mail';

    protected static ?string $slug = 'messaging';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed> */
    public ?array $sms = [];

    /** @var array<string, mixed> */
    public ?array $whatsapp = [];

    public static function canAccess(): bool
    {
        return Erp::can('messaging.manage');
    }

    public function mount(): void
    {
        $s = MessageProvider::where('channel', 'sms')->first();
        $w = MessageProvider::where('channel', 'whatsapp')->first();
        $this->smsForm->fill(['provider' => $s?->provider ?? 'hubtel', 'sender_id' => $s?->sender_id, 'is_active' => $s?->is_active ?? false]);
        $this->whatsappForm->fill(['is_active' => $w?->is_active ?? false]);
    }

    public function smsForm(Schema $schema): Schema
    {
        $saved = MessageProvider::where('channel', 'sms')->first();
        $fields = [];
        foreach (Gateways::FIELDS as $provider => $list) {
            if ($provider === 'meta') {
                continue;
            }
            foreach ($list as $key => $label) {
                $fields[] = TextInput::make("credentials.{$provider}.{$key}")->label($label)->password($key !== 'url')->revealable($key !== 'url')
                    ->placeholder($saved?->provider === $provider && ! empty(($saved->credentials ?? [])[$key]) ? 'Saved — leave empty to keep' : null)
                    ->visible(fn (Get $get) => $get('provider') === $provider);
            }
        }

        return $schema->statePath('sms')->components([
            Section::make('SMS')->description('Choose the SMS company you have an account with. Messages (receipts, booking and appointment reminders, payment notes) go through it. Without one, messages are listed in the message log as "not sent".')
                ->columns(2)->schema([
                    Select::make('provider')->label('SMS provider')->options(Gateways::SMS)->required()->live(),
                    TextInput::make('sender_id')->label('Sender ID')->maxLength(11)->helperText('The name customers see (3–11 letters/digits), registered with your provider. Not needed for a webhook gateway.'),
                    ...$fields,
                    Toggle::make('is_active')->label('Send SMS through this provider')->inline(false),
                    Text::make(fn () => $saved?->tested_at ? 'Last test: '.$saved->tested_at->format('j M H:i').' — '.$saved->test_result : 'Not tested yet.')->columnSpanFull(),
                ]),
        ]);
    }

    public function whatsappForm(Schema $schema): Schema
    {
        $saved = MessageProvider::where('channel', 'whatsapp')->first();

        return $schema->statePath('whatsapp')->components([
            Section::make('WhatsApp Business')->description('Needs a WhatsApp Business account on Meta\'s Cloud API, with message templates approved by Meta (their names go in Messaging templates). Meta charges per conversation. Off until you have the account.')
                ->columns(2)->schema([
                    TextInput::make('credentials.phone_number_id')->label('Phone number ID')->placeholder($saved ? 'Saved — leave empty to keep' : null),
                    TextInput::make('credentials.access_token')->label('Access token (permanent system-user token)')->password()->revealable()->placeholder($saved ? 'Saved — leave empty to keep' : null),
                    Toggle::make('is_active')->label('Send WhatsApp messages (preferred over SMS when a template is on)')->inline(false),
                    Text::make(fn () => $saved?->tested_at ? 'Last test: '.$saved->tested_at->format('j M H:i').' — '.$saved->test_result : '')->columnSpanFull(),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('smsForm')])->id('sms')->livewireSubmitHandler('saveSms')->footer([Actions::make([
                Action::make('saveSms')->label('Save SMS settings')->submit('saveSms'),
                Action::make('testSms')->label('Send a test SMS')->color('gray')->schema([TextInput::make('to')->label('Phone number')->required()->tel()])
                    ->action(fn (array $data) => $this->report(Erp::run(fn ($actor) => app(ProviderSetup::class)->test($actor, 'sms', $data['to'])))),
            ])]),
            Form::make([EmbeddedSchema::make('whatsappForm')])->id('whatsapp')->livewireSubmitHandler('saveWhatsapp')->footer([Actions::make([
                Action::make('saveWhatsapp')->label('Save WhatsApp settings')->submit('saveWhatsapp'),
                Action::make('testWhatsapp')->label('Send Meta\'s "hello_world" test')->color('gray')->schema([TextInput::make('to')->label('WhatsApp number')->required()->tel()])
                    ->action(fn (array $data) => $this->report(Erp::run(fn ($actor) => app(ProviderSetup::class)->test($actor, 'whatsapp', $data['to'])))),
            ])]),
            Section::make('E-mail')->description('E-mail goes through the mail server set in the server\'s .env file (any SMTP server: Gmail / Google Workspace, Zoho, your web host). See the installation guide, Chapter 16.')->schema([
                Text::make('Mail driver: '.config('mail.default').' · From: '.config('mail.from.address').(config('mail.default') === 'log' ? ' — e-mails are only written to the log file, not sent. Set MAIL_MAILER=smtp to send.' : '')),
                Actions::make([Action::make('testEmail')->label('Send a test e-mail')->color('gray')->schema([TextInput::make('to')->label('E-mail address')->required()->email()])
                    ->action(fn (array $data) => $this->report(Erp::run(fn ($actor) => app(Messenger::class)->send($actor->tenantId, 'email', $data['to'],
                        'This is a test e-mail from Synclytics ERP. E-mail works.', 'Test e-mail', 'TEST', $actor->name))))]),
            ]),
        ]);
    }

    public function saveSms(): void
    {
        $d = $this->smsForm->getState();
        $ok = Erp::run(fn ($actor) => app(ProviderSetup::class)->save($actor, 'sms', ['provider' => $d['provider'], 'sender_id' => $d['sender_id'] ?? null,
            'credentials' => $d['credentials'][$d['provider']] ?? [], 'is_active' => $d['is_active'] ?? false]), 'SMS settings saved');
        if ($ok) {
            $this->redirect(self::getUrl());
        }
    }

    public function saveWhatsapp(): void
    {
        $d = $this->whatsappForm->getState();
        $ok = Erp::run(fn ($actor) => app(ProviderSetup::class)->save($actor, 'whatsapp', ['credentials' => $d['credentials'] ?? [], 'is_active' => $d['is_active'] ?? false]), 'WhatsApp settings saved');
        if ($ok) {
            $this->redirect(self::getUrl());
        }
    }

    private function report($message): void
    {
        if (! $message) {
            return;
        }
        $message->status === 'sent'
            ? Notification::make()->title('Sent')->success()->send()
            : Notification::make()->title('Not sent')->body((string) $message->error)->danger()->send();
    }
}
