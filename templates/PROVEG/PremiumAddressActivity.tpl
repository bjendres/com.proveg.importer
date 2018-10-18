{*-------------------------------------------------------------+
| PROVEG StreetImporter Implementation                         |
| Copyright (C) 2018 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+-------------------------------------------------------------*}

<strong>Ergebnis:</strong> {$record.Adressmerkmal} [{$record.AdrMerk}]
<strong>Sendung:</strong> {$record.Sendungsschicksal} [{$record.SdgS}]
<strong>Verarbeitungsdatum:</strong> {$record.FrkDat}

<strong>Kundendaten</strong>
{$customer.name_1} {$customer.name_2} {$customer.name_3} {$customer.name_4}
{$customer.street} {$customer.number}
{$customer.postal_code} {$customer.city}

<strong>Adresseintrag</strong>
{$old_address.name_1} {$old_address.name_2} {$old_address.name_3} {$old_address.name_4}
{$old_address.street} {$old_address.number}
{$old_address.postal_code} {$old_address.city}

<strong>Adresseintrag (neu)</strong>
{$new_address.name_1} {$new_address.name_2} {$new_address.name_3} {$new_address.name_4}
{$new_address.street} {$new_address.number}
{$new_address.postal_code} {$new_address.city}
