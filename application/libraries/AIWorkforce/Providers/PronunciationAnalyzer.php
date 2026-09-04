<?php
namespace AIWorkforce\Providers;

/**
 * Pronunciation Analyzer
 * 
 * Analyzes pronunciation accuracy using STT and text comparison.
 * Supports multiple providers and returns detailed scoring.
 */
class PronunciationAnalyzer
{
    private SpeechToTextProvider $stt;
    
    public function __construct(array $config)
    {
        $this->stt = new SpeechToTextProvider($config);
    }
    
    /**
     * Analyze pronunciation
     * 
     * @param string $audioData Base64-encoded audio
     * @param string $expectedText Expected text to pronounce
     * @param string $language Language code
     * @return array Pronunciation analysis result
     */
    public function analyze(string $audioData, string $expectedText, string $language = 'en'): array
    {
        // Transcribe audio
        $transcription = $this->stt->transcribe($audioData, $language);
        
        if (isset($transcription['error'])) {
            return $transcription;
        }
        
        $actualText = $transcription['text'] ?? '';
        
        // Calculate scores
        $scores = $this->calculateScores($expectedText, $actualText);
        
        return [
            'overall_score' => $scores['overall'],
            'word_score' => $scores['word'],
            'phoneme_score' => $scores['phoneme'],
            'fluency_score' => $scores['fluency'],
            'transcription' => $actualText,
            'expected' => $expectedText,
            'confidence' => $transcription['confidence'] ?? 1.0,
            'feedback' => $this->generateFeedback($scores, $actualText, $expectedText),
            'recommended_exercise' => $this->recommendExercise($scores, $language),
        ];
    }
    
    /**
     * Calculate pronunciation scores
     */
    private function calculateScores(string $expected, string $actual): array
    {
        // Normalize texts
        $expected = strtolower(trim($expected));
        $actual = strtolower(trim($actual));
        
        // Word-level comparison
        $expectedWords = preg_split('/\s+/', $expected);
        $actualWords = preg_split('/\s+/', $actual);
        
        $correctWords = 0;
        $totalWords = count($expectedWords);
        
        foreach ($expectedWords as $i => $word) {
            if (isset($actualWords[$i]) && $this->similarText($word, $actualWords[$i]) > 0.8) {
                $correctWords++;
            }
        }
        
        $wordScore = $totalWords > 0 ? $correctWords / $totalWords : 0;
        
        // Character-level comparison (phoneme approximation)
        $charScore = $this->similarText($expected, $actual);
        
        // Fluency (based on word count match and order)
        $fluencyScore = $this->calculateFluency($expectedWords, $actualWords);
        
        // Overall score (weighted average)
        $overallScore = ($wordScore * 0.4) + ($charScore * 0.3) + ($fluencyScore * 0.3);
        
        return [
            'overall' => round($overallScore * 100, 1),
            'word' => round($wordScore * 100, 1),
            'phoneme' => round($charScore * 100, 1),
            'fluency' => round($fluencyScore * 100, 1),
        ];
    }
    
    /**
     * Calculate text similarity (0-1)
     */
    private function similarText(string $a, string $b): float
    {
        if ($a === $b) return 1.0;
        if (empty($a) || empty($b)) return 0.0;
        
        similar_text($a, $b, $percent);
        return $percent / 100;
    }
    
    /**
     * Calculate fluency score
     */
    private function calculateFluency(array $expected, array $actual): float
    {
        if (empty($expected)) return 1.0;
        
        // Word count match
        $countMatch = 1 - abs(count($expected) - count($actual)) / max(count($expected), 1);
        
        // Order match (check if words are in similar order)
        $orderMatch = 0;
        $lastMatchPos = -1;
        
        foreach ($expected as $i => $word) {
            foreach ($actual as $j => $actualWord) {
                if ($j > $lastMatchPos && $this->similarText($word, $actualWord) > 0.7) {
                    $orderMatch++;
                    $lastMatchPos = $j;
                    break;
                }
            }
        }
        
        $orderScore = count($expected) > 0 ? $orderMatch / count($expected) : 0;
        
        return ($countMatch * 0.5) + ($orderScore * 0.5);
    }
    
    /**
     * Generate feedback based on scores
     */
    private function generateFeedback(array $scores, string $actual, string $expected): string
    {
        $overall = $scores['overall'];
        
        if ($overall >= 90) {
            return "Excellent pronunciation! Your speech is clear and accurate.";
        } elseif ($overall >= 75) {
            return "Good pronunciation with minor issues. Keep practicing!";
        } elseif ($overall >= 60) {
            return "Fair pronunciation. Focus on clarity and word accuracy.";
        } elseif ($overall >= 40) {
            return "Needs improvement. Try speaking more slowly and clearly.";
        } else {
            return "Significant pronunciation issues detected. Practice the recommended exercises.";
        }
    }
    
    /**
     * Recommend exercise based on weaknesses
     */
    private function recommendExercise(array $scores, string $language): string
    {
        if ($scores['phoneme'] < 60) {
            return "Practice individual sounds and phonemes. Focus on difficult consonants and vowels.";
        } elseif ($scores['word'] < 60) {
            return "Practice word pronunciation. Listen to native speakers and repeat.";
        } elseif ($scores['fluency'] < 60) {
            return "Practice speaking in complete sentences. Focus on rhythm and flow.";
        } else {
            return "Continue practicing with longer passages and conversations.";
        }
    }
}
