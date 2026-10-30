<?php

declare(strict_types=1);

namespace Tests\Fixtures\Http;

use App\Plans\CountsAgainstLimit;
use App\Plans\Feature;
use App\Plans\RequiresFeature;
use Illuminate\Http\JsonResponse;

/** Workspace actions marked with plan attributes, for PlanGatesTest (P06-01b). */
final class PlanGateProbe
{
    #[RequiresFeature(Feature::OwnPaystack)]
    public function paystack(): JsonResponse
    {
        return response()->json(['ran' => true]);
    }

    #[CountsAgainstLimit(Feature::StaffUsers)]
    public function addStaff(): JsonResponse
    {
        return response()->json(['ran' => true]);
    }

    public function plain(): JsonResponse
    {
        return response()->json(['ran' => true]);
    }
}
