<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\RestaurantScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Restaurant\ReservationService;
use App\Domain\Restaurant\RestaurantSetup;
use App\Domain\Support\Actor;
use App\Filament\Resources\DiningTables\DiningTableResource;
use App\Filament\Resources\KitchenStations\KitchenStationResource;
use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Models\ModifierGroup;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The restaurant in the new back office (App\Domain\Office\RestaurantScreen): bookings (new, change, no-show, cancel,
 * keep the deposit), bills (read-only), the floor plan, kitchen stations and modifiers (save, archive, restore), and
 * Tips & voids — each through the RestaurantSetup and ReservationService services as the current pages.
 */
class RestaurantController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): RestaurantScreen
    {
        $this->allowed($can);

        return new RestaurantScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Bookings

    public function bookings(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, RestaurantScreen::canBookings())->bookings($request->query()));
    }

    public function bookingForm(Request $request): JsonResponse
    {
        $branch = $request->query('branch');

        return response()->json($this->screen($request, RestaurantScreen::canBookings())->bookingForm(is_string($branch) ? $branch : null));
    }

    public function bookingSave(Request $request, ReservationService $svc, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, RestaurantScreen::canBookings());
        $r = $id ? $screen->bookingsQuery()->findOrFail($id) : new Reservation;
        $this->allowed($id ? ReservationResource::canEdit($r) : ReservationResource::canCreate());
        $d = $request->validate(['branch_id' => [$id ? 'nullable' : 'required', 'uuid'], 'guest_name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'],
            'party_size' => ['required', 'integer', 'min:1', 'max:200'], 'starts_at' => ['required', 'date'], 'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:600'],
            'dining_table_id' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:255']],
            ['branch_id.*' => 'Choose the branch.', 'guest_name.*' => 'Enter the name.', 'party_size.*' => 'Enter how many guests (1 to 200).', 'starts_at.*' => 'Choose the date and time.']);
        if ($id) {
            unset($d['branch_id']); // a booking stays at its branch, as the current form
        }
        foreach (['phone', 'dining_table_id', 'note'] as $k) {
            $d[$k] = ($d[$k] ?? '') === '' ? null : $d[$k];
        }
        $d['duration_minutes'] = $d['duration_minutes'] ?? 90;

        return $this->act(fn (Actor $actor) => ['id' => $svc->save($actor, $r, $d)->id, 'message' => $id ? 'Saved.' : 'Booked.']);
    }

    /** no_show · cancel (reason) · forfeit (keep the deposit; reason). */
    public function bookingAction(Request $request, string $id, ReservationService $svc): JsonResponse
    {
        $r = $this->screen($request, RestaurantScreen::canBookings())->bookingsQuery()->findOrFail($id);
        $act = (string) $request->input('action');
        $branch = Branch::find($r->branch_id);
        $this->allowed($act === 'forfeit' ? Erp::can('restaurant.manage', $branch) : Erp::can('restaurant.serve', $branch));
        $reason = in_array($act, ['cancel', 'forfeit'], true) ? $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'] : '';

        return $this->act(fn (Actor $actor) => ['message' => match ($act) {
            'no_show' => $svc->noShow($actor, $r) ? 'Marked as no-show.' : '',
            'cancel' => $svc->cancel($actor, $r, $reason) ? 'Booking cancelled.' : '',
            'forfeit' => $svc->forfeitDeposit($actor, $r, $reason) ? 'Deposit kept.' : '',
            default => abort(422, 'Unknown action.'),
        }]);
    }

    // -------------------------------------------------------------------------------------------- Bills

    public function bills(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, RestaurantScreen::canBills())->bills($request->query()));
    }

    public function bill(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, RestaurantScreen::canBills());

        return response()->json($screen->bill($screen->billsQuery()->findOrFail($id)));
    }

    // -------------------------------------------------------------------------------------------- Floor plan, kitchen stations

    public function tables(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, RestaurantScreen::canSetup())->tables($request->query()));
    }

    public function tableSave(Request $request, RestaurantSetup $setup, ?string $id = null): JsonResponse
    {
        $this->screen($request, RestaurantScreen::canSetup());
        $t = $id ? DiningTableResource::getEloquentQuery()->findOrFail($id) : new DiningTable;
        $this->allowed($id ? DiningTableResource::canEdit($t) : DiningTableResource::canCreate());
        $d = $request->validate(['branch_id' => [$id ? 'nullable' : 'required', 'uuid'], 'code' => ['required', 'string', 'max:20'], 'area' => ['nullable', 'string', 'max:60'],
            'seats' => ['required', 'integer', 'min:1', 'max:60'], 'pos_x' => ['nullable', 'integer', 'min:0', 'max:99'], 'pos_y' => ['nullable', 'integer', 'min:0', 'max:99']],
            ['branch_id.*' => 'Choose the branch.', 'code.*' => 'Enter the number on the table.', 'seats.*' => 'Enter the seats (1 to 60).']);
        if ($id) {
            unset($d['branch_id']);
        }
        $d['area'] = ($d['area'] ?? '') ?: null;
        $d['pos_x'] = $d['pos_x'] ?? 0;
        $d['pos_y'] = $d['pos_y'] ?? 0;

        return $this->act(fn (Actor $actor) => ['id' => $setup->saveTable($actor, $t, $d)->id, 'message' => 'Saved.']);
    }

    public function stations(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, RestaurantScreen::canSetup())->stations($request->query()));
    }

    public function stationSave(Request $request, RestaurantSetup $setup, ?string $id = null): JsonResponse
    {
        $this->screen($request, RestaurantScreen::canSetup());
        $s = $id ? KitchenStationResource::getEloquentQuery()->findOrFail($id) : new KitchenStation;
        $this->allowed($id ? KitchenStationResource::canEdit($s) : KitchenStationResource::canCreate());
        $d = $request->validate(['branch_id' => [$id ? 'nullable' : 'required', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'auto_print' => ['boolean']],
            ['branch_id.*' => 'Choose the branch.', 'name.*' => 'Name the station.']);
        if ($id) {
            unset($d['branch_id']);
        }

        return $this->act(fn (Actor $actor) => ['id' => $setup->saveStation($actor, $s, $d)->id, 'message' => 'Saved.']);
    }

    // -------------------------------------------------------------------------------------------- Modifiers

    public function modifiers(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, RestaurantScreen::canModifiers())->modifiers($request->query()));
    }

    public function modifierProducts(Request $request): JsonResponse
    {
        $screen = $this->screen($request, RestaurantScreen::canModifiers());

        return response()->json(['results' => $screen->products(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function modifierSave(Request $request, RestaurantSetup $setup, ?string $id = null): JsonResponse
    {
        $this->screen($request, RestaurantScreen::canModifiers());
        $g = $id ? ModifierGroupResource::getEloquentQuery()->findOrFail($id) : new ModifierGroup;
        $this->allowed($id ? ModifierGroupResource::canEdit($g) : ModifierGroupResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'industry_id' => ['nullable', 'string', 'max:40'], 'min_choices' => ['required', 'integer', 'min:0'],
            'max_choices' => ['required', 'integer', 'min:1'], 'is_active' => ['boolean'], 'options' => ['required', 'array', 'min:1'], 'options.*.id' => ['nullable', 'uuid'],
            'options.*.name' => ['nullable', 'string', 'max:120'], 'options.*.product_id' => ['nullable', 'uuid']],
            ['name.*' => 'Enter a name.', 'options.*' => 'Add at least one choice.']);
        if (($d['industry_id'] ?? '') === '') {
            unset($d['industry_id']); // only one industry: it goes to the person's own, as the current form
        }

        return $this->act(fn (Actor $actor) => ['id' => $setup->saveModifierGroup($actor, $g, $d)->id, 'message' => 'Saved.']);
    }

    // -------------------------------------------------------------------------------------------- Archive

    public function archive(Request $request, ArchiveService $svc): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(['table', 'station', 'modifier'])], 'id' => ['required', 'uuid'], 'restore' => ['boolean'],
            'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $this->screen($request, $d['kind'] === 'modifier' ? RestaurantScreen::canModifiers() : RestaurantScreen::canSetup());
        $r = match ($d['kind']) {
            'table' => DiningTableResource::getEloquentQuery()->findOrFail($d['id']),
            'station' => KitchenStationResource::getEloquentQuery()->findOrFail($d['id']),
            default => ModifierGroupResource::getEloquentQuery()->findOrFail($d['id']),
        };
        $this->allowed($svc->allowed($request->user(), $r));

        return $this->act(function (Actor $actor) use ($svc, $r, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $r) : $svc->archive($actor, $r, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Tips & voids

    public function report(Request $request): JsonResponse
    {
        $screen = $this->screen($request, RestaurantScreen::canReport());
        $from = $request->query('from');
        $to = $request->query('to');

        return response()->json($screen->report(is_string($from) ? $from : null, is_string($to) ? $to : null));
    }
}
