<?php

declare(strict_types=1);

namespace Facile\JsonApiPhp\Serialization;

use RuntimeException;

class JsonApiSerializer implements JsonApiSerializerInterface
{
    /** @var string */
    private const REFERENCE_KEYS_TYPE = '_type';

    /** @var string */
    private const REFERENCE_KEYS_ID = '_id';

    /** @var bool */
    private $flattenedRelationships;

    /** @var array */
    private $referencesContainer = [];

    /**
     * @param array $elements
     * @param bool $flattenedRelationships
     *
     * @return array
     */
    public function serialize(array $elements, bool $flattenedRelationships = true): array
    {
        $this->flattenedRelationships = $flattenedRelationships;

        $jsonApiArray = [];
        $jsonApiArray[self::REFERENCE_DATA] = $this->processRecursiveElement(
            $elements
        );

        if (false === empty($this->referencesContainer)) {
            $jsonApiArray[self::REFERENCE_INCLUDED] = array_merge(
                ...array_values(
                    $this->referencesContainer
                )
            );
        }

        return $jsonApiArray;
    }

    /**
     * @param string $jsonString
     * @param bool $flattenedRelationships
     *
     * @throws RuntimeException
     *
     * @return string
     */
    public function serializeString(string $jsonString, bool $flattenedRelationships = true): string
    {
        $elements = json_decode($jsonString, true);
        if (null === $elements) {
            throw new RuntimeException('Not valid JSON string');
        }

        $outputString = json_encode($this->serialize($elements, $flattenedRelationships), JSON_PRETTY_PRINT);
        if (false === $outputString) {
            throw new RuntimeException('Error during JSON encoding of the object');
        }

        return $outputString;
    }

    /**
     * @param array $elements
     * @param bool $flattenedRelationships
     *
     * @return array
     */
    public function __invoke(array $elements, bool $flattenedRelationships = true): array
    {
        return $this->serialize($elements, $flattenedRelationships);
    }

    /**
     * @param mixed $element
     *
     * @return bool
     */
    private static function isReference($element): bool
    {
        if (false === is_array($element)) {
            return false;
        }

        return true === array_keys_exists([self::REFERENCE_KEYS_TYPE, self::REFERENCE_KEYS_ID], $element);
    }

    /**
     * @param mixed $element
     * @param bool $all
     *
     * @return bool
     */
    private static function isArrayOfReference($element, bool $all = true): bool
    {
        if (false === is_array($element)) {
            return false;
        }

        if (true === empty($element)) {
            return false;
        }

        return array_reduce(
            $element,
            static function ($valid, $item) use ($all): bool {
                $isReference = self::isReference($item);
                if (true === $all) {
                    return true === $valid && true === $isReference;
                }

                return true === $valid || true === $isReference;
            },
            $all
        );
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    private static function isKeyReference(string $key): bool
    {
        return $key === self::REFERENCE_KEYS_TYPE || $key === self::REFERENCE_KEYS_ID;
    }

    /**
     * @param array $reference
     * @param bool $checkKeyReference
     *
     * @return array
     */
    private static function filterKeyOrAttributes(array $reference, bool $checkKeyReference = false): array
    {
        $newReference = [];
        foreach ($reference as $key => $item) {
            if (true === is_int($key)) {
                continue;
            }

            if (true === $checkKeyReference && true === self::isKeyReference($key)) {
                continue;
            }

            if (true === self::isReference($item) || true === self::isArrayOfReference($item)) {
                continue;
            }

            if (true === is_array($item) && false === is_a_real_array($item)) {
                $mergedReference = self::filterKeyOrAttributes($item);
                if (false === empty($mergedReference)) {
                    $newReference[$key] = $mergedReference;
                }

                continue;
            }

            $newReference[$key] = $item;
        }

        return $newReference;
    }

    /**
     * @param array $reference
     *
     * @return array
     */
    private static function keepReferenceKeys(array $reference): array
    {
        if (true === empty($reference)) {
            return $reference;
        }

        $keys = [];
        if (true === array_key_exists(self::REFERENCE_KEYS_TYPE, $reference)) {
            $keys[trim(self::REFERENCE_KEYS_TYPE, '_')] = $reference[self::REFERENCE_KEYS_TYPE];
        }

        if (true === array_key_exists(self::REFERENCE_KEYS_ID, $reference)) {
            $keys[trim(self::REFERENCE_KEYS_ID, '_')] = (string) $reference[self::REFERENCE_KEYS_ID];
        }

        return $keys;
    }

    /**
     * @param array $relationships
     * @param bool $recursion = false
     *
     * @return array
     */
    private function extractRelationships(array $relationships, bool $recursion = false): array
    {
        $newRelationships = [];
        foreach ($relationships as $key => $relationship) {
            if (false === is_array($relationship)) {
                continue;
            }

            //$this->flattenedRelationships

            if (false === self::isReference($relationship)) {
                $nestedRelationships = $this->extractRelationships($relationship, true);
                foreach ($nestedRelationships as $subKey => $nestedRelationship) {
                    if (true === is_int($subKey)) {
                        $newRelationships[$key] = [
                            self::REFERENCE_DATA => $nestedRelationship,
                        ];
                    } else {
                        $newRelationships[$key . '.' . $subKey] = [
                            self::REFERENCE_DATA => $nestedRelationship,
                        ];
                    }
                }

                continue;
            }

            $this->referencesContainer[
            $relationship[self::REFERENCE_KEYS_TYPE]][$relationship[self::REFERENCE_KEYS_ID]
            ] = $this->parseReference($relationship);

            $relationship = self::keepReferenceKeys($relationship);
            if (true === $recursion) {
                $newRelationships[$key] = $relationship;
            } else {
                $newRelationships[$key] = [
                    self::REFERENCE_DATA => $relationship,
                ];
            }
        }

        return $newRelationships;
    }

    /**
     * @param array $reference
     * @param bool $includeAttributes
     * @param bool $includeRelationships
     *
     * @return array|null
     */
    private function parseReference(
        array $reference,
        bool $includeAttributes = true,
        bool $includeRelationships = true
    ): ?array {
        $referenceKeys = self::keepReferenceKeys($reference);
        $attributes = self::filterKeyOrAttributes($reference, true);
        $relationships = array_filter(
            $reference,
            static function ($item, $key) {
                if (true === is_int($key)) {
                    return false;
                }

                return true === self::isReference($item) || true === self::isArrayOfReference($item, false);
            },
            ARRAY_FILTER_USE_BOTH
        );

        $parsedReference = $referenceKeys;
        if (true === $includeAttributes && false === empty($attributes)) {
            $parsedReference = array_merge(
                $parsedReference,
                [
                    self::REFERENCE_ATTRIBUTES => $attributes,
                ]
            );
        }

        if (true === $includeRelationships && false === empty($relationships)) {
            $parsedReference = array_merge(
                $parsedReference,
                [
                    self::REFERENCE_RELATIONSHIPS => $this->extractRelationships($relationships),
                ]
            );
        }

        return true === empty($parsedReference)
            ? null
            : $parsedReference;
    }

    /**
     * @param mixed $elements
     *
     * @return array
     */
    private function processRecursiveElement($elements): array
    {
        if (true === is_array($elements)) {
            $parsedReference = $this->parseReference($elements);
            if (null === $parsedReference) {
                return array_map(
                    function ($element) {
                        return $this->processRecursiveElement($element);
                    },
                    $elements
                );
            }

            return $parsedReference;
        }

        return $elements;
    }
}
