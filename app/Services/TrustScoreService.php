<?php

namespace App\Services;

class TrustScoreService
{
    /**
     * Weights per AI layer:
     * challenge_response 20%, speaker_biometric 25%, anti_spoof 25%,
     * replay_detection 15%, face_liveness 15%
     */
    private array $weights = [
        'challenge_response' => 0.20,
        'speaker_biometric'  => 0.25,
        'anti_spoof'         => 0.25,
        'replay_detection'   => 0.15,
        'face_liveness'      => 0.15,
    ];

    /**
     * Calculate weighted trust score from the 5 AI sub-scores.
     * Returns an array with ['score' => int, 'verdict' => string]
     */
    public function calculate(
        int $challengeResponse,
        int $speakerBiometric,
        int $antiSpoof,
        int $replayDetection,
        int $faceLiveness
    ): array {
        $score = (int) round(
            $challengeResponse * $this->weights['challenge_response'] +
            $speakerBiometric  * $this->weights['speaker_biometric']  +
            $antiSpoof         * $this->weights['anti_spoof']         +
            $replayDetection   * $this->weights['replay_detection']   +
            $faceLiveness      * $this->weights['face_liveness']
        );

        return [
            'score'   => $score,
            'verdict' => $this->verdict($score),
        ];
    }

    public function verdict(int $score): string
    {
        if ($score >= 75) return 'PASS';
        if ($score < 40)  return 'FAIL';
        return 'INCONCLUSIVE';
    }
}
