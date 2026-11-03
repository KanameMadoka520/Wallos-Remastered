<?php

declare(strict_types=1);

namespace OTPHP;

use InvalidArgumentException;
use function array_key_exists;
use function assert;
use function in_array;
use function is_int;
use function is_string;

trait ParameterTrait
{
    /**
     * @var array<non-empty-string, mixed>
     */
    private array $parameters = [];

    /**
     * @var non-empty-string|null
     */
    private null|string $issuer = null;

    /**
     * @var non-empty-string|null
     */
    private null|string $label = null;

    private bool $issuer_included_as_parameter = true;

    /**
     * @return array<non-empty-string, mixed>
     */
    public function getParameters(): array
    {
        $parameters = $this->parameters;

        if ($this->getIssuer() !== null && $this->isIssuerIncludedAsParameter() === true) {
            $parameters['issuer'] = $this->getIssuer();
        }

        return $parameters;
    }

    public function getSecret(): string
    {
        $value = $this->getParameter('secret');
        (is_string($value) && $value !== '') || throw new InvalidArgumentException('Invalid "secret" parameter.');

        return $value;
    }

    public function getLabel(): null|string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->setParameter('label', $label);
    }

    public function getIssuer(): null|string
    {
        return $this->issuer;
    }

    public function setIssuer(string $issuer): void
    {
        $this->setParameter('issuer', $issuer);
    }

    public function isIssuerIncludedAsParameter(): bool
    {
        return $this->issuer_included_as_parameter;
    }

    public function setIssuerIncludedAsParameter(bool $issuer_included_as_parameter): void
    {
        $this->issuer_included_as_parameter = $issuer_included_as_parameter;
    }

    public function getDigits(): int
    {
        $value = $this->getParameter('digits');
        (is_int($value) && $value >= 1 && $value <= self::MAX_DIGITS) || throw new InvalidArgumentException('Invalid "digits" parameter.');

        return $value;
    }

    public function getDigest(): string
    {
        $value = $this->getParameter('algorithm');
        (is_string($value) && $value !== '') || throw new InvalidArgumentException('Invalid "algorithm" parameter.');

        return $value;
    }

    public function hasParameter(string $parameter): bool
    {
        return array_key_exists($parameter, $this->parameters);
    }

    public function getParameter(string $parameter): mixed
    {
        if ($this->hasParameter($parameter)) {
            return $this->getParameters()[$parameter];
        }

        throw new InvalidArgumentException(sprintf('Parameter "%s" does not exist', $parameter));
    }

    public function setParameter(string $parameter, mixed $value): void
    {
        $map = $this->getParameterMap();

        if (array_key_exists($parameter, $map) === true) {
            $callback = $map[$parameter];
            $value = $callback($value);
        }

        // Only public label/issuer setters may address object properties.
        // Backport GHSA-2jx3-65f3-xr8r without changing the vendored API.
        if (in_array($parameter, ['label', 'issuer'], true)) {
            $this->{$parameter} = $value;
        } else {
            $this->parameters[$parameter] = $value;
        }
    }

    public function setSecret(string $secret): void
    {
        $this->setParameter('secret', $secret);
    }

    public function setDigits(int $digits): void
    {
        $this->setParameter('digits', $digits);
    }

    public function setDigest(string $digest): void
    {
        $this->setParameter('algorithm', $digest);
    }

    /**
     * @return array<non-empty-string, callable>
     */
    protected function getParameterMap(): array
    {
        return [
            'label' => function (string $value): string {
                assert($value !== '');
                $this->hasColon($value) === false || throw new InvalidArgumentException(
                    'Label must not contain a colon.'
                );

                return $value;
            },
            'secret' => static fn (string $value): string => mb_strtoupper(trim($value, '=')),
            'algorithm' => static function (string $value): string {
                $value = mb_strtolower($value);
                in_array($value, hash_algos(), true) || throw new InvalidArgumentException(sprintf(
                    'The "%s" digest is not supported.',
                    $value
                ));

                return $value;
            },
            'digits' => static function ($value): int {
                $digits = (is_int($value) || is_string($value))
                    ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_DIGITS]])
                    : false;
                $digits !== false || throw new InvalidArgumentException('Digits must be an integer between 1 and 10.');

                return $digits;
            },
            'issuer' => function (string $value): string {
                assert($value !== '');
                $this->hasColon($value) === false || throw new InvalidArgumentException(
                    'Issuer must not contain a colon.'
                );

                return $value;
            },
        ];
    }

    /**
     * @param non-empty-string $value
     */
    private function hasColon(string $value): bool
    {
        $colons = [':', '%3A', '%3a'];
        foreach ($colons as $colon) {
            if (str_contains($value, $colon)) {
                return true;
            }
        }

        return false;
    }
}
