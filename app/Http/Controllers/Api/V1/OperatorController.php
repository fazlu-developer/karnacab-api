<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OperatorFleetService;
use Illuminate\Http\Request;

class OperatorController extends Controller
{
    public function __construct(private readonly OperatorFleetService $fleet) {}

    public function dashboard(Request $request)
    {
        return $this->fleet->dashboard($request->user());
    }

    public function vehicles(Request $request)
    {
        return $this->fleet->vehicles($request->user());
    }

    public function vehicle(Request $request, string $id)
    {
        return $this->fleet->vehicle($request->user(), (int) $id);
    }

    public function storeVehicle(Request $request)
    {
        return $this->fleet->addVehicle($request->user(), $request->all());
    }

    public function updateVehicle(Request $request, string $id)
    {
        return $this->fleet->updateVehicle($request->user(), (int) $id, $request->all());
    }

    public function drivers(Request $request)
    {
        return $this->fleet->drivers($request->user());
    }

    public function driver(Request $request, string $id)
    {
        return $this->fleet->driver($request->user(), (int) $id);
    }

    public function storeDriver(Request $request)
    {
        return $this->fleet->addDriver($request->user(), $request->all());
    }

    public function assign(Request $request, string $vehicleId)
    {
        $data = $request->validate(['driverId' => 'required|integer']);

        return $this->fleet->assignDriver($request->user(), (int) $vehicleId, (int) $data['driverId'], $request->input('reason'));
    }

    public function unassign(Request $request, string $vehicleId)
    {
        return $this->fleet->unassignDriver($request->user(), (int) $vehicleId, $request->input('reason'));
    }

    public function replace(Request $request, string $vehicleId)
    {
        $data = $request->validate(['driverId' => 'required|integer']);

        return $this->fleet->replaceDriver($request->user(), (int) $vehicleId, (int) $data['driverId'], $request->input('reason'));
    }

    public function eligible(Request $request, string $vehicleId)
    {
        return ['drivers' => $this->fleet->eligibleDrivers($request->user(), (int) $vehicleId)];
    }

    public function assignments(Request $request)
    {
        return $this->fleet->assignments($request->user());
    }

    public function leave(Request $request)
    {
        return $this->fleet->leaveList($request->user(), $request->query('status'));
    }

    public function storeLeave(Request $request)
    {
        return $this->fleet->createLeave($request->user(), $request->all());
    }

    public function approveLeave(Request $request, string $id)
    {
        return $this->fleet->reviewLeave($request->user(), (int) $id, 'APPROVE', $request->input('note'));
    }

    public function rejectLeave(Request $request, string $id)
    {
        return $this->fleet->reviewLeave($request->user(), (int) $id, 'REJECT', $request->input('note'));
    }

    public function bookings(Request $request)
    {
        return $this->fleet->bookings($request->user(), $request->query());
    }

    public function booking(Request $request, string $id)
    {
        return $this->fleet->booking($request->user(), (int) $id);
    }

    public function manualBooking(Request $request)
    {
        return $this->fleet->manualBooking($request->user(), $request->all());
    }

    public function tracking(Request $request)
    {
        return $this->fleet->tracking($request->user());
    }

    public function earnings(Request $request)
    {
        return $this->fleet->earnings($request->user());
    }

    public function reports(Request $request)
    {
        return $this->fleet->reports($request->user());
    }

    public function documents(Request $request)
    {
        return $this->fleet->documents($request->user());
    }

    public function notifications(Request $request)
    {
        return $this->fleet->notifications($request->user());
    }

    public function profile(Request $request)
    {
        return $this->fleet->profile($request->user());
    }

    public function updateProfile(Request $request)
    {
        return $this->fleet->updateProfile($request->user(), $request->all());
    }
}
