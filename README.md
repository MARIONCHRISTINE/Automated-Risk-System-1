**Risk
Register 
Automation 
System**





Introduction
At Airtel Kenya, managing risk is important in keeping operations smooth and secure. At the moment the risk management process is done manually using excel spreadsheet where the risk is entered as assessed and tracked by hand, which works to a point but has its down sides. This existing method is time consuming prone o human error and hard to scale. It lacks features like real time recording of risk incidents and tracking of the risk events, the automated risk scoring and proper access control. As more risks are recorded, it gets harder to manage everything efficiently.
Objectives 
•	The development of an application to be a repository of risk events reported by all Airtel Money & other staff supporting Airtel Money.
•	The development of a risk incident reporting interphase to facilitate this reporting.
•	The development of a risk rating engine to facilitate risk assessment.
•	The development of a dashboard to allow for reporting of the evolution of the risks.
•	Incorporate Airtel’s current manual risk register.

Requirements 
1.	Functional requirements 
•	Airtel Money staff & other GSM log in to the Risk incident platform and raise risk incident reports on a continuous basis.
•	Airtel Money Risk & Compliance team members should be able to log into the system and:
Define new risk parameters including risk categories, risk incidents
Provide comments over risk incidents reported
Extract reports from the system on the risk environment of the organisation
•	Risk Owners should be able to log into the system and:
Provide comments on the risks they manage
Extract reports on the risks they manage
•	The system should have interactive dashboards indicating the evolution and state of risks at a given point.
•	The system should have a Bulk Upload feature for easier migration of past events- Strictly CSV/Excel

2.	Non-functional

•	Should be secure and not vulnerable to any attacks
•	Scalable for more users or modules
•	Responsive design
•	Reliable uptime and standalone serever
Development Approach
•	Agile development in sprints
•	GitHub for version control
•	Modules broken down as:
I.	Authentication and user roles
II.	Risk incident entry
III.	Risk scoring(Python microservice)
IV.	Dashboard and reporting
V.	Stand alone deployment







System design
A.	Front-end
HTML, CSS, Javascript
Roles: Staff, Risk Owners, Compliance Team

B.	Back-end (Application layer)
PHP handles: 
•	Authentication and sessions
•	Risk submission logic
•	Dashboard data
•	Communication with risk engine

C.	Python microservice
•	Flask API for risk score calculator
•	Takes risk factors, returns rating and numeric score for PHP

D.	Database layer
MySQL Stores: 
•	Users
•	Risk Incidents
•	Risk Categories, scores, logs

E.	Deployment
Standard hosting, local server preferred for now
Future proof design to allow later integration via subdomain or portal









	

	



			

	

	


	




	



	
Required Integrated Development Environment(IDE’s Required)
1.	Visual studio code(Vs code)
For developing HTML, CSS and Javascript components
For writing and debugging PHP code
With Python Extension for developing and testing the Flask-based risk scoring service(Latest Version 3.10+ for flask microservice)
2.	XAMPP
To run a local server for PHP and MySQL
3.	phpMyAdmin 
For managing the My SQL database
4.	Git and GitHub Desktop 
For source code versioning and collaboration



errors were found during analysis.

38 values were expected, but found 28. (near "(" at position 3700)
SQL query: Copy

-- -------------------------------------------------------- -- -- Dumping data for table `risk_reports` -- INSERT INTO `risk_reports` (`id`, `report_date`, `risk_type`, `is_existing`, `risk_title`, `description`, `category`, `likelihood`, `impact`, `risk_owner_id`, `assigned_to`, `status`, `reported_by`, `created_at`, `updated_at`, `closed_at`, `resolved_by`, `resolution_status`, `department`, `closure_notes`, `evidence_file`, `action_plan`, `due_date`, `follow_up_status`, `follow_up_date`, `is_deleted`, `deleted_at`, `risk_score`, `mitigation_strategy`, `assigned_risk_owner_id`, `custom_fields`) VALUES (35, '2025-07-01', 'new', 'no', 'Wasting Job hours', 'My work mate, Maurice just listens to music from youtube during work hours', 'Idleness and lack of work', 4, 5, 1, 1, 'open', 23, '2025-07-01 09:16:56', '2025-07-08 08:11:25', NULL, NULL, NULL, 'Customer Service', NULL, NULL, 2, '2025-07-17', 'completed', NULL, 0, 0, NULL, 'Critical', '', NULL, '1. Talk to the supervisor to give him work\r2. Having someone to check on him regularly', '2025-07-17', 'completed', NULL, 0, 0, NULL), (44, '2025-07-02', 'existing', 'no', 'edvfcx ', 'asrfvcx ', 'd fvc x', 3, 5, 3, 5, 'open', 23, '2025-07-02 14:42:24', '2025-07-02 14:42:24', NULL, NULL, NULL, 'Customer Service', NULL, NULL, NULL, '2025-07-03', 'On Hold', NULL, 0, 0, NULL, 'Not Assessed', '', NULL, 'dasfvc x', '2025-07-03', 'On Hold', NULL, 0, 0, NULL), (46, '2025-07-03', 'new', 'no', '44fvcdx4tt', 'c4cedfvc', 'tedfbvc x', 2, 5, 2, 5, 'open', 23, '2025-07-03 08:05:47', '2025-07-03 08:05:47', NULL, NULL, NULL, 'Customer Service', NULL, NULL, NULL, '2025-07-11', 'Completed', NULL, 0, 0, NULL, 'Not Assessed', '', NULL, 'e sfzcvrgdfxv', '2025-07-11', 'Completed', NULL, 0, 0, NULL), (47, '2025-07-04', 'existing', 'yes', 'for notificatuion setting ', 'it should just work', 'testing only', 1, 1, 1, 1, 'open', 23, '2025-07-04 12:28:09', '2025-07-04 12:28:09', NULL, NULL, NULL, 'Customer Service', NULL, NULL, NULL, '0000-00-00', 'Not Started', NULL, 0, 0, NULL, 'Not Assessed', '', NULL, 'qscxz\\', '0000-00-00', 'Not Started', NULL, 0, 0, NULL), (49, '2025-07-05', 'new', 'no', 'qesdzx', 'WSDCXZ', 'ESDV', 1, 1, 1, 1, 'open', 25, '2025-07-05 04:09:25', '2025-07-05 04:09:26', NULL, NULL, NULL, 'Customer Service', NULL, NULL, NULL, NULL, 'Not Started', NULL, 0, 0, NULL, 'Not Assessed', '', NULL, NULL, NULL, 'Not Started', NULL, 0, 0, NULL), (51, '2025-07-08', 'new', 'no', 'today only', 'for testing of manage risk', 'nothing', 1, 1, 1, 1, 'open', 25, '2025-07-08 07:11:59', '2025-07-08 07:11:59', NULL, NULL, NULL, 'Customer Service', NULL, NULL, NULL, NULL, 'Not Started', NULL, 0, 0, NULL, 'Not Assessed', '', NULL, NULL, NULL, 'Not Started', NULL, 0, 0, NULL), (56, '2025-07-09', 'new', 'no', 'op;', 'gginuk', '9ylikujh', 1, 1, 1, 1, 'open', 25, '2025-07-09 17:16:14', '2025-07-09 17:16:14', NULL, NULL, NULL, 'Customer Service', NULL, NULL, NULL, NULL, 'Not Started', NULL, 0, 0, NULL, 'Not Assessed', '', NULL, NULL, NULL, 'Not Started', NULL, 0, 0, NULL), (66, '2025-07-14', NULL, NULL, 'dewds', 'dcxsz', 'dcsxz', NULL, NULL, NULL, NULL, 'open', 25, '2025-07-14 08:18:16', '2025-07-14 08:18:16', NULL, NULL, NULL, 'Customer Service', NULL, NULL, NULL, NULL, 'Not Started', NULL, 0, 0, NULL, 'Not Assessed', '', NULL, NULL, NULL, 'Not Started', NULL, 0, 0, NULL), (68, '2025-08-15', 'New', NULL, 'Other - dfsdjyku...', 'dfsdjyku', 'restdyui', 1, 1, 1, 1, 'open', 29, '2025-08-15 09:03:09', '2025-08-15 09:03:09', NULL, NULL, NULL, 'Airtel Money', NULL, NULL, NULL, '2025-08-29', 'Not Started', NULL, 0, 0, NULL, 'Not Assessed', '[\"Other\"]', NULL, '65789', '2025-08-29', 'Not Started', NULL, 0, 0, NULL), (70, '2025-08-15', NULL, NULL, '', 'For Bonke', 'wertyuii', NULL, NULL, NULL, NULL, 'open', 31, '2025-08-15 10:17:06', '2025-08-15 10:17:06', NULL, NULL, NULL, 'Airtel Money', NULL, NULL, NULL, NULL, 'Not Started', 'uploads/risk_documents/689f09220e790_1755253026.docx', 0, 0, '{\"Financial Exposure\":\"1\"}');

MySQL said: Documentation

#1136 - Column count doesn't match value count at row 1
