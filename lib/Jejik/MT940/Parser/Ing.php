<?php
declare(strict_types=1);
/*
 * This file is part of the topay\mt940 forked from imetal\mt940 library, based on Cyberwizzard\MT940 library which is a fork of the Jejik\MT940 library and Jejik\MT940
 *
 * Copyright (c) 2022 Hedzer Oosterwal <info@imetal.nl>
 * Copyright (c) 2017 Berend Dekens <cyberwizzard@gmail.com>
 * Copyright (c) 2012 Sander Marechal <s.marechal@jejik.com>
 * Licensed under the MIT license
 *
 * For the full copyright and license information, please see the LICENSE
 * file that was distributed with this source code.
 */

namespace Jejik\MT940\Parser;
use Jejik\MT940\Parser\GermanBank;
use Jejik\MT940\TransactionInterface;

/**
 * Parser for ING documents
 *
 * @author Hedzer Oosterwal <info@imetal.nl>
 * @author Berend Dekens <cyberwizzard@gmail.com>
 * @author Sander Marechal <s.marechal@jejik.com>
 */
class Ing extends GermanBank
{
	protected const IDENTIFIER_PREF = 'PREF';
	protected const IDENTIFIER_CNTP = 'CNTP';
	protected const IDENTIFIER_REMI = 'REMI';
	protected const IDENTIFIER_RTRN = 'RTRN';
	protected const IDENTIFIER_MARF = 'MARF';
	protected const IDENTIFIER_CSID = 'CSID';
	protected const IDENTIFIER_PURP = 'PURP';
	protected const IDENTIFIER_ULTC = 'ULTC';
	protected const IDENTIFIER_ULTD = 'ULTD';
    /**
     * Test if the document is an ING document
     *
     * @param string $text
     * @return bool
     */
    public function accept(string $text): bool
    {
        return substr($text, 6, 6) === 'INGBNL';
    }

    /**
     * Parse a statement number
     *
     * @param string $text Statement body text
     * @return string|null
     */
    protected function statementNumber(string $text): ?string
    {
        if ($number = $this->getLine('28C', $text)) {
            return $number;
        }

        return null;
    }

    /**
     * Create a Transaction from MT940 transaction text lines
     *
     * ING only provides a book date, not a valuation date. This
     * is opposite from standard MT940 so the AbstractReader will read it
     * as a valueDate. This must be corrected.
     *
     * ING does sometimes supplies a book date inside the description.
     *
     * @param array $lines The transaction text at offset 0 and the description at offset 1
     * @return \Jejik\MT940\Transaction
     */
    protected function transaction(array $lines): TransactionInterface
    {
        $transaction = parent::transaction($lines);

        // If a bookdate was provided, do not switch the value and book dates
        // Since 2014 this should be the case as the 2nd subfield in tag 61 is the book date
        if(is_null($transaction->getBookDate())) {
            $transaction->setBookDate($transaction->getValueDate())
                        ->setValueDate(null);

            if (preg_match('/transactiedatum: (\d{2}-\d{2}-\d{4})/', $lines[1], $match)) {
                $valueDate = \DateTime::createFromFormat('d-m-Y', $match[1]);
                $valueDate->setTime(0, 0, 0);

                $transaction->setValueDate($valueDate);
            }
        }

        return $transaction;
    }

    protected function cntp(array $lines, int $index) : ?string {
        // get :86: line -- it is second in provided array [:61:,:86:,....]
        $cntpLine = isset($lines[1]) ? $lines[1] : null;

        // assure bic line
        if ($cntpLine === null) {
            return null;
        }

        // because we also match CS2 line endings within the payload, remove these before continuing
        $payload = $this->removeNewLinesFromLine($cntpLine);

        // Regex for the 'x' character class of input for MT940. Note: omitted are the single quote (') and forward slash (/) as they make it hard to parse input and usually are not used.
        $swift_regex_x = '[0-9a-zA-Z\-\?\(\)\.,+\{\}\:\s]';
        // Subfields for CNTP: account number / BIC number / Name / City
        $regex = '/\/CNTP\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\//';

        if (preg_match($regex, $payload, $match)) {
            return $match[$index];
        }

        return null;
    }

    /**
     * Get the contra account from a transaction
     *
     * @param array $lines The transaction text at offset 0 and the description at offset 1
     * @return string|null
     */
    protected function contraAccountNumber(array $lines): ?string {
        // because we also match CS2 line endings within the payload, remove these before continuing
        $payload = $this->removeNewLinesFromLine($lines[1]);

        // Regex for the 'x' character class of input for MT940. Note: omitted are the single quote (') and forward slash (/) as they make it hard to parse input and usually are not used.
        $swift_regex_x = '[0-9a-zA-Z\-\?\(\)\.,+\{\}\:\s]';
        // Subfields for CNTP: account number / BIC number / Name / City
        $regex = '/\/CNTP\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\//';

        if (preg_match($regex, $payload, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Get the contra account holder name from a transaction
     *
     * @param array $lines The transaction text at offset 0 and the description at offset 1
     * @return string|null
     */
    protected function contraAccountName(array $lines): ?string {
        // because we also match CS2 line endings within the payload, remove these before continuing
        $payload = $this->removeNewLinesFromLine($lines[1]);

        // Regex for the 'x' character class of input for MT940. Note: omitted are the single quote (') and forward slash (/) as they make it hard to parse input and usually are not used.
        $swift_regex_x = '[0-9a-zA-Z\-\?\(\)\.,+\{\}\:\s]';
        // Subfields for CNTP: account number / BIC number / Name / City
        $regex = '/\/CNTP\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\//';

        /*echo "Lines: " . $payload . "<br/>";
        preg_match($regex, $payload, $match);
        echo "<pre>";
        print_r($match);
        echo "</pre>";*/

        if (preg_match($regex, $payload, $match)) {
            return $match[3];
        }

		return null;
    }

    /**
     * Process the description
     *
     * @param string|null $description
     * @return string
     */
    protected function description(?string $description): string
    {
        // If there is no description, we do not need to parse
        if(is_null($description)) return null;

        // because we also match CS2 line endings within the payload, remove these before continuing
        $payload = $this->removeNewLinesFromLine($description);
        //echo "payload: $payload<br>\n";

        // Regex for the 'x' character class of input for MT940. Note: omitted are the single quote (') and forward slash (/) as they make it hard to parse input and usually are not used.
        $swift_regex_x = '[0-9a-zA-Z\-\?\(\)\.,+\{\}\:\s]';
        // We observed a couple transactions with a / in the second field - this makes is impossible to maintain the 940 format
        // if other fields can be appended later. From emperical proof it looks like this field is always the last and as such
        // we can allow slashes in the second field.
        // Note: if other fields are added afterwards, the will due to this be merged into the description!
        $swift_regex_x2 = '[0-9a-zA-Z\-\?\(\)\.,+\{\}\:\s\/]';
        // Subfields for unstructured description (USTD): description
        // Note: the ING specifies that USTD only has a description field but in practice they specify a type as well, so we extract the next two subfields just in case
        $regex = '/\/USTD\/('.$swift_regex_x.'*)\/('.$swift_regex_x2.'*)\//';

        if (preg_match($regex, $payload, $match)) {
            return $match[1] . $match[2];
        }

        // Subfields for structured description (STRD): type / description
        $regex = '/\/STRD\/('.$swift_regex_x.'*)\/('.$swift_regex_x.'*)\//';

        if (preg_match($regex, $payload, $match)) {
            return $match[2];
        }

        // Fallback, no clue just put the entire line in there
        return $description;
    }

    /**
     * Parse an account number
     *
     * @param string $text Statement body text
     * @return string|null
     */
    protected function accountNumber(string $text) : ?string
    {
        if ($account = $this->getLine('25', $text)) {
            // ING specifies this contains an IBAN followed by a ISO 4217 currency code (3 digit or 3 letters)
            if(preg_match('/^([a-zA-Z0-9]+)[0-9a-zA-Z]{3}$/', $account, $match)) {
                return $match[1];
            }
            return ltrim($account, '0');
        }

        return null;
    }

	/**
     * Get an array of allowed BLZ for this bank
     */
    public function getAllowedBLZ(): array
    {
        return [];
    }

    /**
     * Remove all new lines and carriage returns from provided input line
     */
    private function removeNewLinesFromLine(string $stringLine): string
    {
        return str_replace(["\n", "\r", "\r\n"], '', $stringLine);
    }

	/**
     */
    protected function getSubfield(string $multiUseLine, string $identifier): ?string
    {
        $referenceLine = $this->removeNewLinesFromLine($multiUseLine);

        $identifiers = [
            static::IDENTIFIER_EREF,
			static::IDENTIFIER_PREF,
            static::IDENTIFIER_KREF,
            static::IDENTIFIER_MREF,
            static::IDENTIFIER_CRED,
            static::IDENTIFIER_DEBT,
            static::IDENTIFIER_COAM,
            static::IDENTIFIER_OAMT,
            static::IDENTIFIER_SVWZ,
            static::IDENTIFIER_ABWA,
            static::IDENTIFIER_ABWE,
			static::IDENTIFIER_CNTP,
			static::IDENTIFIER_REMI,
			static::IDENTIFIER_RTRN,
			static::IDENTIFIER_MARF,
			static::IDENTIFIER_CSID,
			static::IDENTIFIER_PURP,
			static::IDENTIFIER_ULTC,
			static::IDENTIFIER_ULTD,
        ];

        $regex = sprintf(
            // '#(?<separator>\?2[0-9])(?<identifier>%s)\+#m',
			'#\/(?<identifier>%s)\/#m',
            implode('|', $identifiers)
        );

        $splitReferenceLine = preg_split(
            $regex,
            $referenceLine,
            -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
        );
        $subfields = [];

        // // check if leading value is an separator: '?20<text>' '?2X' '<identifier>' '<content>'
        // if (current($splitReferenceLine) !== '?20') {
        //     // remove first element if no separator found
        //     next($splitReferenceLine);
        // }

        // expects : '<identifier>' '<content>'
        do {
            $fieldIdentifier = current($splitReferenceLine) ?: 'unknown';
            $fieldContent = next($splitReferenceLine) ?: null;

            if ($fieldContent !== null) {
                $subfields[$fieldIdentifier] = preg_replace('#\?2[0-9]#', '', $fieldContent);
            }
			if (substr($subfields[$fieldIdentifier], -1) === '/') {
				$subfields[$fieldIdentifier] = substr($subfields[$fieldIdentifier], 0, -1);
			}
        } while (next($splitReferenceLine) !== false);

        return $subfields[$identifier] ?? null;
    }

	/**
     * Parse pref for provided transaction lines
     */
    protected function pref(array $lines): ?string
    {
        // get :86: line -- it is second in provided array [:61:,:86:,....]
        $multiUseLine = $lines[1] ?? null;

        /** @var string|null $multiUseLine */
        return $multiUseLine
            ? $this->getSubfield($multiUseLine, static::IDENTIFIER_PREF)
            : null;
    }

    /**
     * Parse bic for provided transaction lines
     */
    protected function bic(array $lines): ?string
    {
        return $this->cntp($lines, 2);
    }

    /**
     * Parse iban for provided transaction lines
     */
    protected function iban(array $lines): ?string
    {
        return $this->cntp($lines, 1);
    }

    /**
     * Parse accountHolder for provided transaction lines
     */
    protected function accountHolder(array $lines): ?string
    {
        return $this->cntp($lines, 3);
    }
}
