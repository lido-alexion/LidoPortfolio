<?php

namespace App\Services\ML;

use App\Models\V7\MlCandidateEvidenceArchive;

/** FEAT-057 — verify immutable candidate evidence and artifact references before inspection. */
class MlCandidateArchiveIntegrityService
{
    /** @return array<string, mixed> */
    public function verify(MlCandidateEvidenceArchive $archive): array
    {
        $encoded = json_encode(
            $archive->evidence,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $evidenceValid = hash_equals($archive->evidence_sha256, hash('sha256', $encoded));

        $artifactStatus = 'unavailable';
        if ($archive->artifact_path !== null && $archive->artifact_sha256 !== null) {
            if (! is_file($archive->artifact_path)) {
                $artifactStatus = 'missing';
            } elseif (! hash_equals($archive->artifact_sha256, (string) hash_file('sha256', $archive->artifact_path))) {
                $artifactStatus = 'mismatch';
            } else {
                $artifactStatus = 'verified';
            }
        }

        return [
            'valid' => $evidenceValid && in_array($artifactStatus, ['verified', 'unavailable'], true),
            'evidence_status' => $evidenceValid ? 'verified' : 'mismatch',
            'artifact_status' => $artifactStatus,
            'model_version_id' => $archive->model_version_id,
            'horizon' => $archive->horizon,
            'model_version' => $archive->model_version,
        ];
    }
}
