-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 23, 2026 at 12:08 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `uspelect`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `firstname` varchar(50) NOT NULL DEFAULT 'Election',
  `lastname` varchar(50) NOT NULL DEFAULT 'Organizer',
  `role` enum('admin','superadmin') DEFAULT 'admin'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password`, `firstname`, `lastname`, `role`) VALUES
(1, 'ORG', '$2y$10$ctrbybOxENe0H.p1oIeOyORKbNHKaarpCztWEcTMxyUROtnN7S.AG', 'Election', 'Organizer', 'admin');

-- --------------------------------------------------------

--
-- Table structure for table `candidates`
--

CREATE TABLE `candidates` (
  `id` int(11) NOT NULL,
  `firstname` varchar(100) DEFAULT NULL,
  `lastname` varchar(100) DEFAULT NULL,
  `position` varchar(100) NOT NULL,
  `election_type` varchar(50) NOT NULL DEFAULT 'USP',
  `party` varchar(100) NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `year_level` varchar(20) NOT NULL DEFAULT '1st Year',
  `department` varchar(100) NOT NULL DEFAULT 'General Studies',
  `program` varchar(100) NOT NULL DEFAULT 'Undergraduate Program',
  `message` text NOT NULL DEFAULT 'I promise to serve with integrity and dedication to all students.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `candidates`
--

INSERT INTO `candidates` (`id`, `firstname`, `lastname`, `position`, `election_type`, `party`, `photo`, `created_at`, `year_level`, `department`, `program`, `message`) VALUES
(24, 'CRISELLE', 'REYES', 'Executive Prime Minister', 'USP', 'ALAB', '6936cb9db85ce.png', '2025-12-08 12:59:09', '2nd Year', 'CCST', 'BSIT', 'When asked what legacy she hopes to leave, she says this: I want to leave a culture where students feel seen, heard, and capable. Where their goals count. Where they know they are never alone in the journey.'),
(25, 'CRIZELLE', 'CARANDANG', 'Prime Minister', 'USP', 'ALAB', '6936cc240c31b.png', '2025-12-08 13:01:24', '3rd Year', 'COA', 'BSA', 'In every campaign season, students look for someone who doesn’t just talk but actually works. That is exactly what the A.L.A.B. Partylist is offering through their Prime Minister candidate, Crizelle Carandang from the College of Accountancy. She is a leader who has been consistent in service and is now ready to bring that energy forward.'),
(26, 'EDRIAN', 'CORACERO', 'Secretary General', 'USP', 'ALAB', '6936cd489298f.png', '2025-12-08 13:06:16', '3rd Year', 'CAS', 'BSE', 'Edrian idea of leadership is built on honest communication. The kind that’s clear, respectful, and sincere. That’s exactly what he hopes to bring to PLSP  consistent updates, open conversations, and systems that help students stay informed and involved.'),
(27, 'JOY ANN', 'COMIA', 'Auditor', 'USP', 'ALAB', '6936cdbe03cc4.png', '2025-12-08 13:08:14', '3rd Year', 'CAS', 'BSE', 'I believe dedication is measured by how you act even when no one is keeping score she says words that capture the heart of her candidacy.Even when no one’s watching, Joy spends her time doing meaningful work: contributing to projects, writing, reflecting, or checking in with family despite the distance. These quiet moments reveal her sincerity and commitment to service not for praise, but for purpose.'),
(28, 'KAORI', 'OSUNA', 'Treasurer', 'USP', 'ALAB', '6936ceb539547.png', '2025-12-08 13:12:21', '3rd Year', 'COA', 'BSAIS', 'Student funds are not just numbers. They represent trust and that trust should be protected. As an accountancy student, she sees budgeting not as a task, but as a duty to the community.These resources often come from public taxes or students own pockets. That alone should remind us to be careful, honest, and efficient.'),
(32, 'LEONARDO', 'DELA CRUZ', 'Prime Minister', 'USP', 'BINI', '69379d3f71af9.png', '2025-12-09 03:53:35', '1st Year', 'CCST', 'BSCE', 'ahwdiahjaoskjkddhkjsdhjvzdjfshiefhisehfi'),
(39, 'MARIA', 'CLARA', 'President', 'Student Council', 'MMK', '', '2026-05-22 04:59:11', '3rd Year', 'CCSE', 'BSIT', 'hey'),
(40, 'MARIA', 'SAMSON', 'President', 'Student Council', 'mapakailanman', '', '2026-05-22 05:00:00', '3rd Year', 'CCSE', 'BSIT', 'heyeye');

-- --------------------------------------------------------

--
-- Table structure for table `dept_settings`
--

CREATE TABLE `dept_settings` (
  `department` varchar(100) NOT NULL,
  `election_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `voting_start` datetime DEFAULT NULL,
  `voting_end` datetime DEFAULT NULL,
  `status` varchar(20) DEFAULT 'upcoming',
  `manual_override` varchar(5) DEFAULT 'no',
  `winners_announced` enum('yes','no') DEFAULT 'no',
  `eligibility_requirements` text DEFAULT NULL,
  `voting_guidelines` text DEFAULT NULL,
  `contact_info` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dept_settings`
--

INSERT INTO `dept_settings` (`department`, `election_name`, `description`, `voting_start`, `voting_end`, `status`, `manual_override`, `winners_announced`, `eligibility_requirements`, `voting_guidelines`, `contact_info`) VALUES
('CBA', 'Student Council Election', '', '2026-05-23 05:18:00', '2026-05-23 05:19:00', 'ongoing', 'yes', 'yes', '', '', ''),
('CCSE', 'Student Council Election', '', '2026-05-23 04:44:00', '2026-05-23 04:46:00', 'ongoing', 'yes', 'no', '', '', ''),
('CTED', 'Student Council Election', '', '2024-01-26 08:00:00', '2024-03-01 17:00:00', 'upcoming', 'no', 'no', '1.Currently Enrolled Student', 'Please select your department representatives.', '');

-- --------------------------------------------------------

--
-- Table structure for table `election_info`
--

CREATE TABLE `election_info` (
  `id` int(11) NOT NULL DEFAULT 1,
  `election_name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('upcoming','ongoing','closed') DEFAULT 'upcoming',
  `manual_override` enum('yes','no') DEFAULT 'no',
  `voting_start` datetime NOT NULL,
  `voting_end` datetime NOT NULL,
  `vote_counting` enum('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `winners_announced` enum('yes','no') NOT NULL DEFAULT 'no',
  `eligibility_requirements` text NOT NULL,
  `voting_guidelines` text NOT NULL,
  `contact_info` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `election_info`
--

INSERT INTO `election_info` (`id`, `election_name`, `description`, `status`, `manual_override`, `voting_start`, `voting_end`, `vote_counting`, `winners_announced`, `eligibility_requirements`, `voting_guidelines`, `contact_info`, `updated_at`, `department`) VALUES
(1, 'USP Student Election 2025', 'Annual Student Government Election', 'ongoing', 'yes', '2026-05-23 04:55:00', '2026-05-23 04:56:00', 'completed', 'no', '1. Currently enrolled Student', '1. One vote per student\r\n2. Select one candidate per position\r\n3. Voting closes automatically at the end date\r\n4. No changes allowed after submission\r\n5. Results are final and binding', 'Election Committee Email: uspelect@gmail.com \r\nPhone: 09123456789 \r\nOffice: Student Affairs Building, Room 101', '2026-05-22 21:33:50', NULL),
(2, 'Student Council Election', '', 'ongoing', 'yes', '2024-01-26 08:00:00', '2024-03-01 17:00:00', 'completed', 'yes', '1.Currently Enrolled Student', 'Please select your department representatives.', '', '2026-05-22 20:14:41', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `stu_no` varchar(100) NOT NULL,
  `lastname` varchar(100) NOT NULL,
  `firstname` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL,
  `program` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`stu_no`, `lastname`, `firstname`, `department`, `program`) VALUES
('21-96541', 'DELA CRUZ ', 'JUAN', 'CCSE', 'BSIT'),
('22-07015', 'MONTESINES', 'BILLY JOE', 'CCSE', 'BSIT'),
('22-08552', 'BASBAS', 'DRYDEN MICHEL', 'CCSE', 'BSIT'),
('23-09424', 'SAMSAMAN', 'PAUL BENEDICT', 'CCSE', 'BSIT'),
('23-09694', 'CONCEJERO', 'FIONA ANNE GAILE', 'CCSE', 'BSIT'),
('23-09711', 'SILANG', 'ALLEN JAMES', 'CCSE', 'BSIT'),
('23-10255', 'PONCIANO', 'IVAN KARL', 'CCSE', 'BSIT'),
('23-10389', 'DELOS SANTOS ', 'ANA MARIE', 'CTED', 'BSE'),
('23-10826', 'MERIDA', 'GLENN CHRISTOPHER', 'CCSE', 'BSIT'),
('23-16917', 'DAVID', 'JOHN ARIEL', 'CCSE', 'BSIT'),
('24-13669', 'NOZA', 'LYKA JOY', 'CBA', 'BSBA'),
('25-21119', 'MONTESINES', 'MILDRED', 'CBA', 'BSBA');

-- --------------------------------------------------------

--
-- Table structure for table `voters`
--

CREATE TABLE `voters` (
  `stu_no` varchar(100) NOT NULL,
  `lastname` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL,
  `program` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` enum('not_voted','voted') DEFAULT 'not_voted',
  `voted_at` datetime DEFAULT NULL,
  `has_accepted_guidelines` tinyint(4) DEFAULT 0,
  `active_session_id` varchar(255) DEFAULT NULL,
  `login_token` varchar(255) DEFAULT NULL,
  `verify_status` varchar(20) DEFAULT 'NONE',
  `device_token` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `voters`
--

INSERT INTO `voters` (`stu_no`, `lastname`, `email`, `department`, `program`, `password`, `status`, `voted_at`, `has_accepted_guidelines`, `active_session_id`, `login_token`, `verify_status`, `device_token`) VALUES
('21-96541', 'DELA CRUZ', 'drydenbasbas@gmail.com', 'CCSE', 'BSIT', '$2y$10$1z6BBIt3BmM.r23wPypuO.WzAd85Pofkef7iw1q7t/GHNa52vYW1u', 'not_voted', NULL, 0, NULL, NULL, 'NONE', NULL),
('22-07015', 'MONTESINES', 'billymontesines@gmail.com', 'CCSE', 'BSIT', '$2y$10$7af54p057uJPhqTnQmGEKePML9TjA2Gd3cv8aY8N1MH1Ww6Ayt/RW', 'not_voted', NULL, 0, '8d4icc3f2svq18a05sddnb1cbo', NULL, 'NONE', 'd81f851d82578904c04923a03d61f9e1fabe5b016b070eca6556a89461b22de8'),
('22-08552', 'BASBAS', 'micheldryden@gmail.com', 'CCSE', 'BSIT', '$2y$10$nNVCsJgKl8T8Ketk2yI59.0PXaU8oFpAzVWFY28IvE7pVRdFV2pGi', 'not_voted', NULL, 0, NULL, NULL, 'NONE', 'ee7ab2e50d1c3d63d2fcda4dba505bf1492223c0a97c4739c1c69ca52a1f8228'),
('23-09424', 'SAMSAMAN', 'paulemberga001@gmail.com', 'CCSE', 'BSIT', '$2y$10$jG46Ih16saS.mIOsudlcU.cWHey1ZEPZABf1rJmIjZS46YWtisLLW', 'voted', '2026-05-23 05:23:27', 0, 'jeb11ogea7jf7qo577m732192t', NULL, 'NONE', 'af1cf20f57fabb0016e7b770642900ad58c2dedba18028de6f84d42bf7b8a75d'),
('23-09694', 'CONCEJERO', 'concejerofionaanne@gmail.com', 'CCSE', 'BSIT', '$2y$10$jqXlddaHW5J0radmrVWj1OcHtXjzk120ZG3K2ilm5AiIhnMTi.c/.', 'not_voted', NULL, 0, NULL, NULL, 'NONE', '24b0d6b7b929f3918a9a0abfd0b0200552c5ebe17a7d65eb321bdb15d59b8a63'),
('23-10255', 'PONCIANO', 'aybanponciano123@gmail.com', 'CCSE', 'BSIT', '$2y$10$kJZHwJDuJf6bpU.HAlJ5a.OLQoG5CzqLlPDkXKyejhkT5s/erqxr6', 'not_voted', NULL, 0, 'dn0faj1qke55olaagho23311li', NULL, 'NONE', 'a1623d38fc80c8edff27c438510973cc77b63c85661c82959f05b69c97b83d35'),
('23-10826', 'MERIDA', 'glennchristophermerida@gmail.com', 'CCSE', 'BSIT', '$2y$10$Rv.Trv.zqNtpQQJhJ6JfROM9BHn32gTLuMYxdD3945SKa9J6vRI9a', 'not_voted', NULL, 0, 'jsr2fqvujr5fd9l15bbv9l4j64', NULL, 'NONE', 'a4d7f5d8e21e7e737807ea501c882c4ca9142f1a9d839c5a60fb1c668e6dcc79'),
('23-16917', 'DAVID ', 'johnarieldavid40@gmail.com', 'CCSE', 'BSIT', '$2y$10$/cIW4iDAl9kg2tgZMtUGAOcE.TNt5tnWMAPID/PUUQxpfy3tDV1n2', 'not_voted', NULL, 0, NULL, NULL, 'NONE', 'acb1791a8d9dbbfca114b865cfa9d2953b29d8c016f1bcc1ff151f38272d2e68'),
('24-13669', 'NOZA', 'kazukihiro130@gmail.com', 'CBA', 'BSBA', '$2y$10$Y9cpjSOD1UrErydhTJ2la.69ihBtSlt/WJxU1fSBKoCvfa7PbNrby', 'not_voted', NULL, 0, NULL, NULL, 'NONE', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `votes`
--

CREATE TABLE `votes` (
  `vote_id` int(11) NOT NULL,
  `stu_no` varchar(50) NOT NULL,
  `prime_minister` varchar(50) DEFAULT NULL,
  `executive_prime_minister` varchar(50) DEFAULT NULL,
  `secretary_general` varchar(50) DEFAULT NULL,
  `treasurer` varchar(50) DEFAULT NULL,
  `auditor` varchar(50) DEFAULT NULL,
  `vote_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `candidate_id` int(11) NOT NULL,
  `student_council` varchar(255) DEFAULT NULL,
  `sc_president` varchar(255) DEFAULT NULL,
  `sc_vice_president` varchar(255) DEFAULT NULL,
  `sc_secretary` varchar(255) DEFAULT NULL,
  `sc_treasurer` varchar(255) DEFAULT NULL,
  `sc_auditor` varchar(255) DEFAULT NULL,
  `sc_rep1` varchar(255) DEFAULT NULL,
  `sc_rep2` varchar(255) DEFAULT NULL,
  `sc_rep3` varchar(255) DEFAULT NULL,
  `sc_rep4` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `votes`
--

INSERT INTO `votes` (`vote_id`, `stu_no`, `prime_minister`, `executive_prime_minister`, `secretary_general`, `treasurer`, `auditor`, `vote_timestamp`, `candidate_id`, `student_council`, `sc_president`, `sc_vice_president`, `sc_secretary`, `sc_treasurer`, `sc_auditor`, `sc_rep1`, `sc_rep2`, `sc_rep3`, `sc_rep4`) VALUES
(1, '24-13669', NULL, NULL, NULL, NULL, NULL, '2026-05-22 21:53:32', 0, NULL, 'Abstain', 'Abstain', 'Abstain', 'Abstain', 'Abstain', 'Abstain', 'Abstain', 'Abstain', 'Abstain');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `candidates`
--
ALTER TABLE `candidates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `dept_settings`
--
ALTER TABLE `dept_settings`
  ADD PRIMARY KEY (`department`);

--
-- Indexes for table `election_info`
--
ALTER TABLE `election_info`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`stu_no`);

--
-- Indexes for table `voters`
--
ALTER TABLE `voters`
  ADD PRIMARY KEY (`stu_no`),
  ADD KEY `stu_no` (`stu_no`),
  ADD KEY `stu_no_2` (`stu_no`);

--
-- Indexes for table `votes`
--
ALTER TABLE `votes`
  ADD PRIMARY KEY (`vote_id`),
  ADD KEY `stu_no` (`stu_no`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `candidates`
--
ALTER TABLE `candidates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `votes`
--
ALTER TABLE `votes`
  MODIFY `vote_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `votes`
--
ALTER TABLE `votes`
  ADD CONSTRAINT `votes_ibfk_1` FOREIGN KEY (`stu_no`) REFERENCES `students` (`stu_no`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
