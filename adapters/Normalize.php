<?php

namespace App\Scripts\Adapters;

class Normalize 
{
    /**
     * Turn a show name into a plain 'match_key' for spotting the same show across platforms.
     * Rules: lowercase, drop punctuation, squeeze multiple spaces into one, trim.
     */
    public static function makeMatchKey(?string $name): string 
    {
        if (empty($name)) {
            return "";
        }
        
        $text = strtolower($name);
        
        // Keep letters, numbers, and spaces. Replace everything else with a space.
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        
        // Collapse multiple spaces into one
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
}