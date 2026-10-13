<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Declarative validation. Returns only the declared fields, cleaned and typed.
 *
 *   $data = Validator::make($input, [
 *       'title'    => 'required|string|max:200',
 *       'priority' => 'in:low,medium,high',
 *       'due_at'   => 'nullable|datetime',
 *   ]);
 *
 * Rules: required, nullable, string, int, numeric, bool, email, datetime, date,
 * time, in:a,b, min:n, max:n (length for strings, value for numbers), ids (list of ints),
 * regex:/.../ (must be last).
 * Fields absent from input are omitted unless required (handy for PATCH).
 */
final class Validator
{
	public static function make(array $input, array $rules): array
	{
		$clean = [];
		$errors = [];
		foreach ($rules as $field => $ruleString) {
			$rules = self::parse($ruleString);
			$present = array_key_exists($field, $input);
			$value = $input[$field] ?? null;
			if (is_string($value)) {
				$value = trim($value);
			}
			$empty = $value === null || $value === '' || $value === [];

			if ($empty) {
				if (isset($rules['required'])) {
					$errors[$field] = 'This field is required.';
				} elseif ($present) {
					// An explicitly cleared optional field.
					if (isset($rules['ids'])) {
						$clean[$field] = [];
					} elseif (isset($rules['nullable'])) {
						$clean[$field] = null;
					} elseif (isset($rules['string'])) {
						$clean[$field] = '';
					} else {
						$errors[$field] = 'This field cannot be empty.';
					}
				}
				continue;
			}

			$error = null;
			$value = self::apply($value, $rules, $error);
			if ($error !== null) {
				$errors[$field] = $error;
			} else {
				$clean[$field] = $value;
			}
		}
		if ($errors) {
			throw HttpException::validation($errors);
		}
		return $clean;
	}

	private static function parse(string $ruleString): array
	{
		$rules = [];
		// regex may contain "|", so it must be the last rule.
		if (($pos = strpos($ruleString, 'regex:')) !== false) {
			$rules['regex'] = substr($ruleString, $pos + 6);
			$ruleString = rtrim(substr($ruleString, 0, $pos), '|');
		}
		foreach (array_filter(explode('|', $ruleString)) as $rule) {
			[$name, $arg] = array_pad(explode(':', $rule, 2), 2, true);
			// Flag rules map to true so isset() works for them.
			$rules[$name] = $arg;
		}
		return $rules;
	}

	private static function apply(mixed $value, array $rules, ?string &$error): mixed
	{
		if (isset($rules['ids'])) {
			if (!is_array($value)) {
				$value = explode(',', (string) $value);
			}
			$ids = [];
			foreach ($value as $v) {
				if (!is_numeric($v) || (int) $v < 1) {
					$error = 'Invalid selection.';
					return null;
				}
				$ids[] = (int) $v;
			}
			return array_values(array_unique($ids));
		}
		if (is_array($value)) {
			$error = 'Invalid value.';
			return null;
		}

		if (isset($rules['int'])) {
			if (filter_var($value, FILTER_VALIDATE_INT) === false) {
				$error = 'Must be a whole number.';
				return null;
			}
			$value = (int) $value;
		} elseif (isset($rules['numeric'])) {
			if (!is_numeric($value)) {
				$error = 'Must be a number.';
				return null;
			}
			$value = (float) $value;
		} elseif (isset($rules['bool'])) {
			$b = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
			if ($b === null) {
				$error = 'Must be true or false.';
				return null;
			}
			return $b;
		} elseif (isset($rules['email'])) {
			$value = mb_strtolower((string) $value);
			if (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 190) {
				$error = 'Enter a valid email address.';
				return null;
			}
		} elseif (isset($rules['datetime'])) {
			$dt = self::toUtc((string) $value);
			if ($dt === null) {
				$error = 'Enter a valid date and time.';
				return null;
			}
			return $dt;
		} elseif (isset($rules['date'])) {
			$d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
			if (!$d || $d->format('Y-m-d') !== $value) {
				$error = 'Enter a valid date (YYYY-MM-DD).';
				return null;
			}
		} elseif (isset($rules['time'])) {
			if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value)) {
				$error = 'Enter a valid time (HH:MM).';
				return null;
			}
			$value = strlen((string) $value) === 5 ? $value . ':00' : $value;
		} else {
			$value = (string) $value;
		}

		if (isset($rules['in']) && !in_array((string) $value, explode(',', $rules['in']), true)) {
			$error = 'Choose one of: ' . str_replace(',', ', ', $rules['in']) . '.';
			return null;
		}
		$isNum = is_int($value) || is_float($value);
		if (isset($rules['min'])) {
			$min = (float) $rules['min'];
			if ($isNum ? $value < $min : mb_strlen((string) $value) < $min) {
				$error = $isNum ? "Must be at least {$rules['min']}." : "Must be at least {$rules['min']} characters.";
				return null;
			}
		}
		if (isset($rules['max'])) {
			$max = (float) $rules['max'];
			if ($isNum ? $value > $max : mb_strlen((string) $value) > $max) {
				$error = $isNum ? "Must be at most {$rules['max']}." : "Must be at most {$rules['max']} characters.";
				return null;
			}
		}
		if (isset($rules['regex']) && !preg_match($rules['regex'], (string) $value)) {
			$error = 'Invalid format.';
			return null;
		}
		return $value;
	}

	/**
	 * Accepts ISO 8601 with Z/offset (what the browser sends) or a plain
	 * "Y-m-d H:i[:s]" taken as UTC. Returns "Y-m-d H:i:s" in UTC.
	 */
	public static function toUtc(string $value): ?string
	{
		$utc = new DateTimeZone('UTC');
		if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
			return null;
		}
		try {
			$dt = new DateTimeImmutable($value, $utc);
		} catch (\Exception) {
			return null;
		}
		$y = (int) $dt->format('Y');
		if ($y < 2000 || $y > 2100) {
			return null;
		}
		return $dt->setTimezone($utc)->format('Y-m-d H:i:s');
	}
}
