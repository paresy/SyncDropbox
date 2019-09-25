# SyncDropbox
Das Modul lädt alle relevanten Einstellungen und Daten periodisch in Ihr Dropbox Konto hoch, um im Problemfall dies als Backup entsprechend wieder einspielen zu können. Eine Anleitung zum Einspielen der Dateien als Backup finden Sie in der Dokumentation: https://www.symcon.de/service/dokumentation/datensicherung/backup-einspielen/

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Automatisches, periodisches hochladen aller Einstellungen und Daten 
* Anzeige der letzten Synchronisation
* Fortschritt der Synchronisation kann in der Konsole beobachtet werden 

### 2. Voraussetzungen

- IP-Symcon ab Version 5.2

### 3. Software-Installation

- Über den Modul Store das Modul 'Sync (Dropbox)' installieren.
- Alternativ über das Modul Control folgende URL hinzufügen: ´https://github.com/paresy/SyncDropbox`

### 4. Einrichten der Instanzen in IP-Symcon

- Unter "Instanz hinzufügen" ist das 'Sync (Dropbox)' Modul aufgeführt.  

__Konfigurationsseite__:

Name              | Beschreibung
----------------- | ---------------------------------
Registrieren      | Stellt die Verknüpfung zum Dropbox Konto her
Sync erzwingen    | Startet eine neue Synchronization
ReSync Intervall  | Zeit bis zur Erneuten synchronisierung (Minimum sind 60 Minuten)
Zeitlimit         | Maximale Dauer der initialen Synchronization (Modifikation nur erforderlich bei sehr vielen Dateien)
Pfad-Filter       | Semikolon getrennte Liste von Dateien/Pfaden die ignoriert werden sollen bei der Synchronization
Größenlimit       | Maximale Größe einer Datei (Größere Dateien werden ignoriert)

### 5. Statusvariablen und Profile

Es werden keine zusätzliche Statusvariablen und Profile erstellt.

### 6. WebFront

Im WebFront werden keine Variablen angezeigt.

### 7. PHP-Befehlsreferenz

Es stehen keine weiteren Befehle zur Verfügung. 