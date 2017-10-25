[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.01-blue.svg)]()
[![Version](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)  
[![Version](https://img.shields.io/badge/Symcon%20Version-4.3%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-4-3-%28Stable%29-Changelog)


# IPSMySQLArchiv

Zusätzliches Archiv für MySQL Datenbanken.

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang) 
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Vorbereitungen](#4-vorbereitungen)
5. [Einrichten der Instanz in IPS](#5-einrichten-der--instanz-in-ips)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz) 
7. [Parameter / Modul-Infos](#7-parameter--modul-infos) 
8. [Anhang](#8-anhang)
9. [Lizenz](#9-lizenz)

## 1. Funktionsumfang

Variablenveränderungen in einer MySQL-Datenbank speichern.  
Auslesen mit ACMySQL_* PHP-Funktionen (kompatibel mit den AC_* Befehlen).  

**Achtung:**  
  Dieses 'Archive Control' ersetzt nicht das Original in IPS.  
  Es wird nicht zur Visualisierung der IPS eigenen Graphen genutzt.  
  Ebenso kann das Logging-Verhalten nicht über die Einstellungen der Variable angepaßt werden, sondern nur in der Instanz 'Archiv MySQL'.  
  Der Typ Zähler ist aktuell nicht verfügbar.

  Um die gespeicherten Daten darzustellen, müssen Umsetzungen von dritten (wie z.B. Highcharts) genutzt werden.  
  Eine angepaßte Highcharts.ips.php ist unter 'docs' beigefügt.  

## 2. Voraussetzungen

 - IPS ab Version 4.3
 - MySQL Server


## 3. Installation

**IPS 4.3:**  
   Bei privater Nutzung: Über das Modul-Control folgende URL hinzufügen.  
   `git://github.com/Nall-chan/IPSMySQLArchiv.git`  

   **Bei kommerzieller Nutzung (z.B. als Errichter oder Integrator) wenden Sie sich bitte an den Autor.**  

## 4. Vorbereitungen

 - Der MySQL-Server muss von IPS aus erreichbar sein.  
 - Es muss ein Benutzer mit allen erforderlichen Rechten vorhanden sein. (Daten & Struktur)  

## 5. Einrichten der Instanz in IPS

  - Auf der Willkommen-Seite von IPS dem Link 'Instanz hinzufügen' öffnen.  
  - In den Schnellfilter MySQL eingeben.  
  - Den Eintrag 'Archiv MySQL' wählen und mit OK bestätigen.  
  - Die Instanz über einen weiten Klick auf OK erzeugen.  
  - Im folgenden Dialog des Konfigurators müssen jetzt erst die Zugangsdaten zum MySQL-Server eingebene werden.  
  - Die zu loggenden Variablen müssen über den Button 'hinzufügen' und dann durch einen Klick auf den Stift ausgewählt werden.  



## 6. PHP-Funktionsreferenz  

Es sind alle IPS Befehle des Original Archive-Control vorhanden.  
Der Prefix muss nur von AC_ gegen ACMySQL_ ersetzt werden.  
Da aktuell keine Zähler unterstützt werden, werden diese Rückgabewerte immer mit 0 übergeben.  

## 7. Parameter / Modul-Infos  


| Name         | Eigenschaft | Typ     | Standardwert | Funktion                          |
| :----------: | :---------: | :-----: | :----------: | :-------------------------------: |
| Host         | Host        | string  |              | Hostname / IP-Adresse             |
| Datenbank    | Database    | string  | ips          | Name der Datenbank                |
| Benutzername | Username    | integer |              | Benutzername MySQL                |
| Passwort     | Password    | string  |              | Passwort MySQL                    |
| Variablen    | Variables   | string  | {}           | JSON-String mit allen VariablenID |


## 8. Anhang

**Changlog:**  

Version 1.01:  
 - Erstes offizielles Release

## 9. Lizenz

  IPS-Modul:  
  [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)  