<?php

declare(strict_types=1);

namespace Atom\Types;

/**
 * Point represents a point in 2D space with X and Y coordinates.
 * This is an immutable class that can be used to represent positions,
 * vectors, or any other 2D data points.
 *
 * Example usage:
 * $point = new Point(52.2297, 21.0122);
 * echo $point->toString(); // Output: "52.2297,21.0122"
 * echo $point->toFormat("Lat: %F, Lng: %F"); // Output: "Lat: 52.229700, Lng: 21.012200"
 */
readonly class Point 
{
    /**
     * @param float $latitude  Latitude coordinate
     * @param float $longitude Longitude coordinate
     */
    public function __construct(
        public float $latitude,
        public float $longitude
    ) {}

    /**
     * Converts the point to a string representation
     *
     * @return string The string representation in "lat,lng" format
     */
    public function toString(): string 
    {
        return "{$this->latitude},{$this->longitude}";
    }

    /**
     * Format the point coordinates according to the provided format string.
     *
     * @param string $format The format to use for formatting the coordinates.
     *                       Should contain two %f or %F placeholders for latitude and longitude respectively.
     * @return string The formatted string with coordinates.
     */
    public function toFormat (string $format): string
    {
        return \sprintf($format, $this->latitude, $this->longitude);
    }

    /**
     * Returns the point in Well-Known Text (WKT) format for SQL databases
     * @return string SQL POINT representation in format: POINT(lat long)
     */
    public function toSql(): string
    {
        return "POINT({$this->latitude}, {$this->longitude})";
    }

    public function toPrepareSql(string $xName, string $yName): string
    {
        return "POINT(:{$xName}, :{$yName})";
    }
}
