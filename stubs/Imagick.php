<?php

if (!class_exists('Imagick')) {
    class Imagick implements Iterator {
        public function __construct(string ...$files) {}
        public function setResolution(float $x, float $y): bool { return true; }
        public function readImage(string $filename): bool { return true; }
        public function setImageFormat(string $format): bool { return true; }
        public function writeImage(string $filename): bool { return true; }
        public function clear(): bool { return true; }
        
        // Iterator interface implementation
        public function current(): mixed { return $this; }
        public function key(): mixed { return 0; }
        public function next(): void {}
        public function rewind(): void {}
        public function valid(): bool { return false; }
    }
}
