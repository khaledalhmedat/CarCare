<?php

namespace App\Services;

use App\Models\User;
use App\Models\Technician;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class TechnicianService
{

    private function uploadCertifications($files, ?Technician $technician = null): array
    {
        if (!$files || empty($files)) {
            return [];
        }

        $uploadedFiles = [];

        if ($technician && $technician->certifications) {
            $oldCertifications = json_decode($technician->certifications, true) ?? [];
            foreach ($oldCertifications as $oldFile) {
                Storage::disk('public')->delete($oldFile);
            }
        }

        foreach ($files as $file) {
            $path = $file->store('technician-certifications', 'public');
            $uploadedFiles[] = $path;
        }

        return $uploadedFiles;
    }


    public function updateProfile(User $user, array $data): Technician
    {
        try {
            DB::beginTransaction();

            Log::info('Updating technician profile', ['user_id' => $user->id, 'data' => $data]);

            $technician = $user->technician;

            if (isset($data['certifications']) && !empty($data['certifications'])) {
                $certificationsPaths = $this->uploadCertifications($data['certifications'], $technician);
                $data['certifications'] = json_encode($certificationsPaths);
            } else {
                unset($data['certifications']);
            }

            if ($technician) {
                Log::info('Updating existing technician', ['id' => $technician->id]);

                foreach ($data as $key => $value) {
                    $technician->$key = $value;
                }
                $technician->save();

                Log::info('Technician updated', ['technician' => $technician->toArray()]);
            } else {
                Log::info('Creating new technician');

                $technician = new Technician();
                $technician->user_id = $user->id;
                $technician->specialization = $data['specialization'] ?? null;
                $technician->experience_years = $data['experience_years'] ?? null;
                $technician->phone = $data['phone'] ?? null;
                $technician->city = $data['city'] ?? null;
                $technician->hourly_rate = $data['hourly_rate'] ?? null;
                $technician->certifications = $data['certifications'] ?? null;
                $technician->save();
            }

            DB::commit();

            $freshTechnician = $technician->fresh();
            Log::info('Final technician data', ['technician' => $freshTechnician->toArray()]);

            return $freshTechnician;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating technician: ' . $e->getMessage());
            throw $e;
        }
    }


    public function getProfile(User $user): ?Technician
    {
        return $user->technician;
    }


    public function updateAvailability(User $user, bool $isAvailable): bool
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $technician->is_available = $isAvailable;
        return $technician->save();
    }
}
