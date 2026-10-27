<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\Api\V1;

use App\Application\Actions\UploadChart;
use App\Domain\Chart\Models\ChartUpload;
use App\Domain\Identity\Models\User;
use App\Infrastructure\Storage\ChartStorage;
use App\Presentation\Http\Requests\UploadChartRequest;
use App\Presentation\Http\Resources\ChartUploadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ChartUploadController
{
    /**
     * Upload a chart screenshot. 201 for a new upload; 200 with `deduplicated: true` when this user
     * already uploaded the same image.
     */
    public function upload(UploadChartRequest $request, UploadChart $uploadChart): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $uploadChart($user, $request->toData());

        return (new ChartUploadResource($result->upload->load(['stock', 'latestAnalysis'])))
            ->withDeduplicated($result->deduplicated)
            ->response()
            ->setStatusCode($result->deduplicated ? 200 : 201);
    }

    public function show(ChartUpload $chartUpload): ChartUploadResource
    {
        Gate::authorize('view', $chartUpload);

        return new ChartUploadResource($chartUpload->load(['stock', 'latestAnalysis']));
    }

    /**
     * Stream the stored (re-encoded PNG) image. Only reachable with the owner's session.
     */
    public function image(ChartUpload $chartUpload, ChartStorage $storage): StreamedResponse
    {
        Gate::authorize('view', $chartUpload);

        return $storage->response($chartUpload) ?? throw new NotFoundHttpException('The image file is missing.');
    }

    public function destroy(ChartUpload $chartUpload): Response
    {
        Gate::authorize('delete', $chartUpload);

        $chartUpload->delete();

        return response()->noContent();
    }
}
