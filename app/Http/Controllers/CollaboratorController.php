<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Collaborators\CollaboratorAccess;
use App\Collaborators\CollaboratorView;
use App\Collaborators\PropertyUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Liberu\RealEstate\Properties\Models\Property;
use Symfony\Component\HttpFoundation\Response;

/** Collaborator API (read-only, Sanctum) and attachment downloads. Every answer goes through CollaboratorAccess. */
final class CollaboratorController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $properties = CollaboratorAccess::scopeProperties(Property::query(), $user)->orderByDesc('id')->get();

        return response()->json(['data' => $properties->map(fn (Property $p): array => CollaboratorView::row($p, CollaboratorAccess::assignment($user, $p)))->values()]);
    }

    public function show(Request $request, int $property): JsonResponse
    {
        $user = $request->user();
        $assignment = CollaboratorAccess::assignment($user, $property);
        abort_if($assignment === null, 404);

        return response()->json(['data' => CollaboratorView::row($assignment->property, $assignment) + [
            'novedades' => CollaboratorView::updates($assignment)->map(fn (PropertyUpdate $u): array => CollaboratorView::update($u))->values(),
        ]]);
    }

    /** A file of an update: the collaborator it was shared with, or staff of the same team. */
    public function attachment(Request $request, PropertyUpdate $update, int $index): Response
    {
        $user = $request->user();
        $staff = CollaboratorAccess::isStaff($user) && method_exists($user, 'belongsToTeam') && $user->belongsToTeam($update->team);
        abort_unless($staff || CollaboratorAccess::canSee($user, $update), 404);
        $file = ($update->attachments ?? [])[$index] ?? null;
        abort_if($file === null || ! Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'] ?? basename($file['path']));
    }
}
