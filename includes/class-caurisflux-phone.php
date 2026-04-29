<?php
/**
 * Normalisation E.164 pour tous les pays (ISO 3166-1 alpha-2).
 *
 * Approche sans dépendance Composer (libphonenumber pèse ~25 Mo et provoque
 * des conflits dans l'écosystème WP). On fait du best-effort :
 *   1. Si le numéro est déjà en E.164 valide → renvoyé tel quel.
 *   2. Sinon on tente de détecter l'indicatif via le code pays ISO fourni
 *      (250+ pays mappés vers leur dial code ITU-T).
 *   3. On strip le 0 ou trunk leader éventuel, on préfixe avec l'indicatif,
 *      et on valide le résultat par regex E.164 stricte.
 *   4. Si rien ne match → chaîne vide (le backend CaurisFlux gérera).
 *
 * Pour les indicatifs partagés (NANP : US/CA/...), on accepte tel quel : si
 * le numéro fourni est déjà E.164 c'est OK ; sinon on ne peut pas désambiguïser
 * sans le code pays ISO.
 *
 * @package CaurisFlux
 */

defined( 'ABSPATH' ) || exit;

final class CaurisFlux_Phone {

	/** Regex E.164: + suivi d'un chiffre 1-9 puis 6 à 14 chiffres = total 7-15. */
	private const E164_REGEX = '/^\+[1-9]\d{6,14}$/';

	/**
	 * Map ISO 3166-1 alpha-2 → indicatif téléphonique ITU-T (sans le +).
	 * Source : ITU-T E.164 + correctifs de territoires/protectorats.
	 */
	private const DIAL_CODES = array(
		'AF' => '93',
		'AL' => '355',
		'DZ' => '213',
		'AS' => '1684',
		'AD' => '376',
		'AO' => '244',
		'AI' => '1264',
		'AG' => '1268',
		'AR' => '54',
		'AM' => '374',
		'AW' => '297',
		'AU' => '61',
		'AT' => '43',
		'AZ' => '994',
		'BS' => '1242',
		'BH' => '973',
		'BD' => '880',
		'BB' => '1246',
		'BY' => '375',
		'BE' => '32',
		'BZ' => '501',
		'BJ' => '229',
		'BM' => '1441',
		'BT' => '975',
		'BO' => '591',
		'BA' => '387',
		'BW' => '267',
		'BR' => '55',
		'IO' => '246',
		'VG' => '1284',
		'BN' => '673',
		'BG' => '359',
		'BF' => '226',
		'BI' => '257',
		'KH' => '855',
		'CM' => '237',
		'CA' => '1',
		'CV' => '238',
		'KY' => '1345',
		'CF' => '236',
		'TD' => '235',
		'CL' => '56',
		'CN' => '86',
		'CX' => '61',
		'CC' => '61',
		'CO' => '57',
		'KM' => '269',
		'CK' => '682',
		'CR' => '506',
		'HR' => '385',
		'CU' => '53',
		'CW' => '599',
		'CY' => '357',
		'CZ' => '420',
		'CD' => '243',
		'DK' => '45',
		'DJ' => '253',
		'DM' => '1767',
		'DO' => '1809',
		'EC' => '593',
		'EG' => '20',
		'SV' => '503',
		'GQ' => '240',
		'ER' => '291',
		'EE' => '372',
		'SZ' => '268',
		'ET' => '251',
		'FK' => '500',
		'FO' => '298',
		'FJ' => '679',
		'FI' => '358',
		'FR' => '33',
		'GF' => '594',
		'PF' => '689',
		'GA' => '241',
		'GM' => '220',
		'GE' => '995',
		'DE' => '49',
		'GH' => '233',
		'GI' => '350',
		'GR' => '30',
		'GL' => '299',
		'GD' => '1473',
		'GP' => '590',
		'GU' => '1671',
		'GT' => '502',
		'GG' => '44',
		'GN' => '224',
		'GW' => '245',
		'GY' => '592',
		'HT' => '509',
		'HN' => '504',
		'HK' => '852',
		'HU' => '36',
		'IS' => '354',
		'IN' => '91',
		'ID' => '62',
		'IR' => '98',
		'IQ' => '964',
		'IE' => '353',
		'IM' => '44',
		'IL' => '972',
		'IT' => '39',
		'CI' => '225',
		'JM' => '1876',
		'JP' => '81',
		'JE' => '44',
		'JO' => '962',
		'KZ' => '7',
		'KE' => '254',
		'KI' => '686',
		'XK' => '383',
		'KW' => '965',
		'KG' => '996',
		'LA' => '856',
		'LV' => '371',
		'LB' => '961',
		'LS' => '266',
		'LR' => '231',
		'LY' => '218',
		'LI' => '423',
		'LT' => '370',
		'LU' => '352',
		'MO' => '853',
		'MG' => '261',
		'MW' => '265',
		'MY' => '60',
		'MV' => '960',
		'ML' => '223',
		'MT' => '356',
		'MH' => '692',
		'MQ' => '596',
		'MR' => '222',
		'MU' => '230',
		'YT' => '262',
		'MX' => '52',
		'FM' => '691',
		'MD' => '373',
		'MC' => '377',
		'MN' => '976',
		'ME' => '382',
		'MS' => '1664',
		'MA' => '212',
		'MZ' => '258',
		'MM' => '95',
		'NA' => '264',
		'NR' => '674',
		'NP' => '977',
		'NL' => '31',
		'NC' => '687',
		'NZ' => '64',
		'NI' => '505',
		'NE' => '227',
		'NG' => '234',
		'NU' => '683',
		'NF' => '672',
		'KP' => '850',
		'MK' => '389',
		'MP' => '1670',
		'NO' => '47',
		'OM' => '968',
		'PK' => '92',
		'PW' => '680',
		'PS' => '970',
		'PA' => '507',
		'PG' => '675',
		'PY' => '595',
		'PE' => '51',
		'PH' => '63',
		'PN' => '64',
		'PL' => '48',
		'PT' => '351',
		'PR' => '1787',
		'QA' => '974',
		'CG' => '242',
		'RE' => '262',
		'RO' => '40',
		'RU' => '7',
		'RW' => '250',
		'BL' => '590',
		'SH' => '290',
		'KN' => '1869',
		'LC' => '1758',
		'MF' => '590',
		'PM' => '508',
		'VC' => '1784',
		'WS' => '685',
		'SM' => '378',
		'ST' => '239',
		'SA' => '966',
		'SN' => '221',
		'RS' => '381',
		'SC' => '248',
		'SL' => '232',
		'SG' => '65',
		'SX' => '1721',
		'SK' => '421',
		'SI' => '386',
		'SB' => '677',
		'SO' => '252',
		'ZA' => '27',
		'KR' => '82',
		'SS' => '211',
		'ES' => '34',
		'LK' => '94',
		'SD' => '249',
		'SR' => '597',
		'SJ' => '47',
		'SE' => '46',
		'CH' => '41',
		'SY' => '963',
		'TW' => '886',
		'TJ' => '992',
		'TZ' => '255',
		'TH' => '66',
		'TL' => '670',
		'TG' => '228',
		'TK' => '690',
		'TO' => '676',
		'TT' => '1868',
		'TN' => '216',
		'TR' => '90',
		'TM' => '993',
		'TC' => '1649',
		'TV' => '688',
		'VI' => '1340',
		'UG' => '256',
		'UA' => '380',
		'AE' => '971',
		'GB' => '44',
		'US' => '1',
		'UY' => '598',
		'UZ' => '998',
		'VU' => '678',
		'VA' => '39',
		'VE' => '58',
		'VN' => '84',
		'WF' => '681',
		'EH' => '212',
		'YE' => '967',
		'ZM' => '260',
		'ZW' => '263',
		'AX' => '358',
	);

	/**
	 * Normalise un numéro vers le format E.164 (`+xxxxxxxxxx`).
	 *
	 * @param string $phone        Numéro saisi par l'utilisateur.
	 * @param string $country_iso2 Code pays ISO 3166-1 alpha-2 (ex: 'SN', 'FR', 'US').
	 *                             Vide si inconnu.
	 *
	 * @return string Numéro en E.164 valide, ou '' si normalisation impossible.
	 */
	public static function to_e164( string $phone, string $country_iso2 = '' ): string {
		$phone = trim( $phone );
		if ( '' === $phone ) {
			return '';
		}

		// Conserve uniquement les chiffres et le + initial.
		$cleaned = preg_replace( '/[^\d+]/', '', $phone );
		if ( null === $cleaned || '' === $cleaned ) {
			return '';
		}

		// Cas 1 : déjà en E.164 → on valide.
		if ( '+' === $cleaned[0] ) {
			return self::is_valid_e164( $cleaned ) ? $cleaned : '';
		}

		// Cas 2 : "00" préfixe international (ex: 00237xxx en Europe).
		if ( 0 === strpos( $cleaned, '00' ) ) {
			$candidate = '+' . substr( $cleaned, 2 );
			return self::is_valid_e164( $candidate ) ? $candidate : '';
		}

		// Cas 3 : on a un code pays → on tente d'ajouter l'indicatif.
		$country_iso2 = strtoupper( trim( $country_iso2 ) );
		if ( '' !== $country_iso2 && isset( self::DIAL_CODES[ $country_iso2 ] ) ) {
			$dial = self::DIAL_CODES[ $country_iso2 ];

			// 3a — Le numéro contient déjà l'indicatif sans + (ex: "237699735940").
			if ( 0 === strpos( $cleaned, $dial ) ) {
				$candidate = '+' . $cleaned;
				if ( self::is_valid_e164( $candidate ) ) {
					return $candidate;
				}
			}

			// 3b — Numéro national pur, on préfixe avec l'indicatif (en strippant
			// le 0 trunk éventuel).
			$national  = ltrim( $cleaned, '0' );
			$candidate = '+' . $dial . $national;
			if ( self::is_valid_e164( $candidate ) ) {
				return $candidate;
			}
		}

		// Pas de code pays fourni : on refuse le best-effort pour éviter de
		// produire un E.164 ambigu (ex: "771234567" pourrait être Russie ou
		// Sénégal). L'utilisateur DOIT fournir un + explicite ou un préfixe 00.
		return '';
	}

	public static function is_valid_e164( string $phone ): bool {
		return 1 === preg_match( self::E164_REGEX, $phone );
	}

	/**
	 * Renvoie l'indicatif d'un pays ISO 3166-1 alpha-2 (sans +), ou '' si inconnu.
	 */
	public static function dial_code( string $country_iso2 ): string {
		$country_iso2 = strtoupper( trim( $country_iso2 ) );
		return self::DIAL_CODES[ $country_iso2 ] ?? '';
	}

	/**
	 * @return array<string,string> Map complète ISO2 → dial.
	 */
	public static function all_dial_codes(): array {
		return self::DIAL_CODES;
	}
}
