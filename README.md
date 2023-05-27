[![SDK](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/) 
[![Version](https://img.shields.io/badge/Modul%20Version-3.40-blue.svg)]() 
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)  
[![Version](https://img.shields.io/badge/Symcon%20Version-5.1%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857) 
[![Check Style](https://github.com/Nall-chan/MySQLArchive/workflows/Check%20Style/badge.svg)](https://github.com/Nall-chan/MySQLArchive/actions) 
[![Run Tests](https://github.com/Nall-chan/MySQLArchive/workflows/Run%20Tests/badge.svg)](https://github.com/Nall-chan/MySQLArchive/actions)  
[![Spenden](https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_SM.gif)](#9-spenden) 

# Symcon-Modul: MySQL Archiv  <!-- omit in toc -->

Zusätzliches Archiv für MySQL Datenbanken.

## Dokumentation <!-- omit in toc -->

**Inhaltsverzeichnis**

- [1. Funktionsumfang](#1-funktionsumfang)
- [2. Voraussetzungen](#2-voraussetzungen)
- [3. Installation](#3-installation)
- [4. Vorbereitungen](#4-vorbereitungen)
- [5. Einrichten der Instanz in IPS](#5-einrichten-der-instanz-in-ips)
- [6. PHP-Funktionsreferenz](#6-php-funktionsreferenz)
- [7. Parameter](#7-parameter)
- [8. Anhang](#8-anhang)
  - [1. GUID der Module](#1-guid-der-module)
  - [2. Changelog](#2-changelog)
- [9. Spenden](#9-spenden)
- [10. Lizenz](#10-lizenz)

## 1. Funktionsumfang

Variablenveränderungen in einer MySQL-Datenbank speichern.  
Auslesen mit ACMYSQL_* PHP-Funktionen (kompatibel mit den AC_* Befehlen).  

**Achtung:**  
  Dieses 'Archive Control' ersetzt nicht das Original in IPS.  
  Es wird nicht zur Visualisierung der IPS eigenen Graphen genutzt.  
  Ebenso kann das Logging-Verhalten nicht über die Einstellungen der Variable angepasst werden, sondern nur in der Instanz 'Archiv MySQL'.  
  Der Typ Zähler ist aktuell nicht verfügbar.

  Um die gespeicherten Daten darzustellen, müssen Umsetzungen von dritten (wie z.B. Highcharts) genutzt werden.  
  Eine angepasste Highcharts.ips.php ist unter 'docs' beigefügt und kann über die Konfiguration der Instanz in den Objektbaum kopiert werden.  

## 2. Voraussetzungen

 - IPS ab Version 5.1
 - MySQL Server


## 3. Installation

**IPS 5.1:**  
   Bei privater Nutzung: Über den 'Module-Store' in IPS das Modul `MySQL Archiv` hinzufügen.  

   **Bei kommerzieller Nutzung (z.B. als Errichter oder Integrator) wenden Sie sich bitte an den Autor.**  

## 4. Vorbereitungen

 - Der MySQL-Server muss von IPS aus erreichbar sein.  
 - Es muss ein Benutzer mit allen erforderlichen Rechten vorhanden sein. (Daten & Struktur)  

## 5. Einrichten der Instanz in IPS

  - Auf der Willkommen-Seite von IPS dem Link 'Instanz hinzufügen' öffnen.  
  - In den Schnellfilter MySQL eingeben.  
  - Den Eintrag 'Archiv MySQL' wählen und mit OK bestätigen.  
  - Die Instanz über einen weiten Klick auf OK erzeugen.  
  - Im folgenden Dialog des Konfigurator müssen jetzt erst die Zugangsdaten zum MySQL-Server eingegeben werden.  
  - Die zu loggenden Variablen müssen über den Button 'hinzufügen' und dann durch einen Klick auf den Stift ausgewählt werden.  

## 6. PHP-Funktionsreferenz  

Es sind alle IPS Befehle des Original Archive-Control vorhanden.  
Der Prefix muss nur von AC_ gegen ACMYSQL_ ersetzt werden.  
Da aktuell keine Zähler unterstützt werden, werden diese Rückgabewerte immer mit 0 übergeben.  

## 7. Parameter

|     Name     | Eigenschaft |   Typ   | Standardwert |             Funktion              |
| :----------: | :---------: | :-----: | :----------: | :-------------------------------: |
|     Host     |    Host     | string  |              |       Hostname / IP-Adresse       |
|  Datenbank   |  Database   | string  |     ips      |        Name der Datenbank         |
| Benutzername |  Username   | integer |              |        Benutzername MySQL         |
|   Passwort   |  Password   | string  |              |          Passwort MySQL           |
|  Variablen   |  Variables  | string  |      {}      | JSON-String mit allen VariablenID |


## 8. Anhang

###  1. GUID der Module

 
|         Modul         |  Typ   | Prefix  |                  GUID                  |
| :-------------------: | :----: | :-----: | :------------------------------------: |
| Archive Control MySQL | Device | ACMYSQL | {FDCB334A-AFFF-4785-9596-D380252CEE4E} |

### 2. Changelog

Version 3.40:
- Version für IPS 7.0  

Version 3.35:
- Das Anzeigen der Konfiguration konnte fehlschlagen, wenn in einer Tabelle keine Werte vorhanden waren.  
- ACMYSQL_GetAggregationVariables konnte fehlschlagen, wenn in einer Tabelle keine Werte vorhanden waren.  
- ACMYSQL_ChangeVariableID konnte fehlschlagen,wenn in einer Tabelle keine Werte vorhanden waren.  
- ACMYSQL_GetLoggedValues konnte fehlschlagen,wenn in einer Tabelle keine Werte vorhanden waren.  
- ACMYSQL_GetAggregatedValues konnte fehlschlagen,wenn in einer Tabelle keine Werte vorhanden waren.  

Version 3.31:
- Fix für defekte 3.20 Version.  

Version 3.20:  
- Release für IPS 5.1 und den Module-Store   

Version 3.10:  
- Buffer Tread-Safe mit Semaphore abgesichert.     

Version 3.00:  
- Loggen der Daten von Nachrichtenschlange entkoppelt.  

Version 2.50:  
- Anpassungen für IPS 5.1  

Version 2.00:  
 - Anpassungen für IPS 5.0
 - Modul intern umgebaut
 - Konfiguration für WebConsole verbessert    

Version 1.02:  
 - Fixes für IPS 5.0  

Version 1.01:  
 - Erstes offizielles Release

## 9. Spenden  
  
  Die Library ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:  

<a href="https://www.paypal.com/donate?hosted_button_id=G2SLW2MEMQZH2" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>

[![Wunschliste](https://img.shields.io/badge/Wunschliste-Amazon-ff69fb.svg)](https://www.amazon.de/hz/wishlist/ls/YU4AI9AQT9F?ref_=wl_share)


## 10. Lizenz

  IPS-Modul:  
  [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)  