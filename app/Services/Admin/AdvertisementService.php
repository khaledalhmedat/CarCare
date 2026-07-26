<?php

namespace App\Services\Admin;

use App\Models\Advertisement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AdvertisementService
{
    public function create(array $data, UploadedFile $image, ?int $adminId): Advertisement
    {
        $data['image_path'] = $image->store('advertisements', 'public');
        $data['created_by'] = $adminId;
        $data['updated_by'] = $adminId;

        return Advertisement::create($data);
    }

    public function update(Advertisement $ad, array $data, ?UploadedFile $image, ?int $adminId): Advertisement
    {
        if ($image) {
            // replace: delete the old file, store the new one
            if ($ad->image_path) {
                Storage::disk('public')->delete($ad->image_path);
            }
            $data['image_path'] = $image->store('advertisements', 'public');
        }

        $data['updated_by'] = $adminId;
        $ad->update($data);

        return $ad->fresh();
    }

    public function setActive(Advertisement $ad, bool $isActive, ?int $adminId): Advertisement
    {
        $ad->update([
            'is_active' => $isActive,
            'updated_by' => $adminId,
        ]);

        return $ad->fresh();
    }

    /**
     * Soft delete. The stored image file is intentionally kept so a restore
     * would not lose the asset; hard cleanup can be a future maintenance task.
     */
    public function delete(Advertisement $ad): void
    {
        $ad->delete();
    }
}
