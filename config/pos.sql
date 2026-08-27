-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Creato il: Ago 13, 2026 alle 06:41
-- Versione del server: 10.4.32-MariaDB
-- Versione PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `opensagra_pos`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `casse_stampanti`
--

CREATE TABLE `casse_stampanti` (
  `cassa_id` varchar(50) NOT NULL,
  `tipo_stampante` varchar(20) NOT NULL,
  `nome_indirizzo` varchar(100) DEFAULT NULL,
  `porta` int(11) DEFAULT NULL,
  `qz_host` varchar(255) DEFAULT NULL,
  `abilita_contanti` tinyint(1) NOT NULL DEFAULT 1,
  `abilita_carta` tinyint(1) NOT NULL DEFAULT 0,
  `abilita_satispay` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `casse_stampanti`
--

INSERT INTO `casse_stampanti` (`cassa_id`, `tipo_stampante`, `nome_indirizzo`, `porta`, `qz_host`) VALUES

-- --------------------------------------------------------

--
-- Struttura della tabella `dettagli_vendita`
--

CREATE TABLE `dettagli_vendita` (
  `id` int(11) NOT NULL,
  `vendita_id` int(11) DEFAULT NULL,
  `prodotto` varchar(100) DEFAULT NULL,
  `quantita` int(11) DEFAULT NULL,
  `prezzo_unitario` decimal(10,2) DEFAULT NULL,
  `line_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `line_discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total_before_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `totale` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `receipt_config`
--

CREATE TABLE `receipt_config` (
  `cassa_id` varchar(50) NOT NULL,
  `custom_header_text` text DEFAULT NULL,
  `cut_each_item` tinyint(1) NOT NULL DEFAULT 1,
  `enable_logo_print` tinyint(1) NOT NULL DEFAULT 1,
  `logo_path` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dump dei dati per la tabella `receipt_config`
--

INSERT INTO `receipt_config` (`cassa_id`, `custom_header_text`, `cut_each_item`, `enable_logo_print`, `logo_path`, `updated_at`) VALUES
('GLOBAL', 'Festa Cavalleri e Fumeri 25/26 Luglio 2026', 0, 1, 'uploads/receipt_logo_global_1786385955.png', '2026-08-10 18:19:15');

-- --------------------------------------------------------

--
-- Struttura della tabella `stock`
--

CREATE TABLE `stock` (
  `id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `item_sort` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `quantity_available` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- --------------------------------------------------------

--
-- Struttura della tabella `vendite`
--

CREATE TABLE `vendite` (
  `id` int(11) NOT NULL,
  `data_ora` datetime DEFAULT current_timestamp(),
  `totale` decimal(10,2) DEFAULT NULL,
  `importo_pagato` decimal(10,2) DEFAULT NULL,
  `resto` decimal(10,2) DEFAULT NULL,
  `cassa_id` varchar(50) DEFAULT NULL,
  `sconto` decimal(10,2) DEFAULT NULL,
  `metodo_pagamento` varchar(50) DEFAULT NULL,
  `stornato` int(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `dettagli_vendita`
--
ALTER TABLE `dettagli_vendita`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vendita_id` (`vendita_id`);

--
-- Indici per le tabelle `receipt_config`
--
ALTER TABLE `receipt_config`
  ADD PRIMARY KEY (`cassa_id`);

--
-- Indici per le tabelle `stock`
--
ALTER TABLE `stock`
  ADD PRIMARY KEY (`id`);

--
-- Indici per le tabelle `vendite`
--
ALTER TABLE `vendite`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `dettagli_vendita`
--
ALTER TABLE `dettagli_vendita`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT per la tabella `vendite`
--
ALTER TABLE `vendite`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `dettagli_vendita`
--
ALTER TABLE `dettagli_vendita`
  ADD CONSTRAINT `dettagli_vendita_ibfk_1` FOREIGN KEY (`vendita_id`) REFERENCES `vendite` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
