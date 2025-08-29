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


Error
Static analysis:

2 errors were found during analysis.

Ending quote ' was expected. (near "" at position 2880)
14 values were expected, but found 4. (near "(" at position 2779)
SQL query: Copy

-- -- Dumping data for table `users` -- INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `role`, `department`, `phone`, `is_active`, `created_at`, `updated_at`, `status`, `area_of_responsibility`, `assigned_risk_owner_id`) VALUES (1, 'staff', 'staff@airtel.africa', '$2y$10$IWY.euXIm9QyaaJayzjRk.m11XiMl92LZOsP3tRj58TBUy76/FQvq', 'Airtel Staff', 'admin', 'IT', NULL, 1, '2025-06-19 13:01:20', '2025-07-30 11:15:12', 'approved', NULL, NULL), (2, 'admin1@airtel.africa', 'admin1@airtel.africa', '$2y$10$qivdI.iwtj0eRM9ikOBoXOBBBqvAguxSEySR9exH8SVRHy/VI5HvS', 'admin1@airtel.africa', 'admin', 'General', NULL, 1, '2025-06-19 13:04:44', '2025-06-19 13:04:44', 'approved', NULL, NULL), (3, 'staff7@airtel.africa', 'staff7@airtel.africa', '$2y$10$HDzkoQNJp2L8KztiA4UxDOWxm3xROB//LAG8U1xT.2Gb3kpKJPLCq', 'staff7@airtel.africa', 'staff', 'General', NULL, 1, '2025-06-19 13:45:26', '2025-07-30 09:45:39', 'approved', NULL, NULL), (4, 'compliance@airtel.co.ke', 'compliance@airtel.co.ke', '$2y$10$5bO3DQL7I/s6D1aIqqE9BOkSf21hQThvffPa8TXkUG/H0ZialliJS', 'compliance@airtel.co.ke', 'compliance_team', 'General', NULL, 1, '2025-06-19 14:31:44', '2025-06-19 14:31:44', 'approved', NULL, NULL), (5, 'riskowner2@airtel.africa', 'riskowner2@airtel.africa', '$2y$10$5m/UW71k52b764krikRkuehDFqbFDSN/nio7lgMaxzXQG/wjTSQ0y', 'riskowner2@airtel.africa', 'risk_owner', 'General', NULL, 0, '2025-06-20 08:31:36', '2025-07-30 09:46:15', 'approved', NULL, NULL), (6, 'riskowner9@airtel.africa', 'riskowner9@airtel.africa', '$2y$10$Sg0AKDPJZuqgQW0tNMezDO.4ymA.bFkIGEXc006chyNhRoxL.0X6u', 'riskowner9@airtel.africa', 'risk_owner', 'General', NULL, 1, '2025-06-20 09:05:19', '2025-06-20 09:05:19', 'approved', NULL, NULL), (7, 'riskowner10@airtel.africa', 'riskowner10@airtel.africa', '$2y$10$dUMjMqEPcHhdmiM6rY6PpOo.umSFLz1rIZ.O2CYiWTfmYLNIR8maK', 'riskowner10@airtel.africa', 'risk_owner', 'General', NULL, 1, '2025-06-20 09:24:00', '2025-06-20 09:24:00', 'approved', NULL, NULL), (8, 'riskowner100@airtel.africa', 'riskowner100@airtel.africa', '$2y$10$ci9ksQJBR8deSA6Oy93nY.m.D67QIFbFViJNquJFWYR2DodenCob2', 'riskowner100@airtel.africa', 'risk_owner', 'General', NULL, 1, '2025-06-20 12:55:38', '2025-06-20 12:55:38', 'approved', NULL, NULL), (9, 'riskowner101@airtel.africa', 'riskowner101@airtel.africa', '$2y$10$hXHCMM5J9dAawLtidRkSwu.yDA4UnNIGlHbCCw/HSM5qXQEmWCeou', 'riskowner101@airtel.africa', 'risk_owner', 'General', NULL, 1, '2025-06-20 13:02:08', '2025-06-20 13:02:08', 'approved', NULL, NULL), (10, 'riskowner102@airtel.africa', 'riskowner102@airtel.africa', '$2y$10$qh7vjdhg45YrNHLp8QlQ6uhut8ZZIhHPqc6usCjyjFyXZtntu0QVu', 'riskowner102@airtel.africa', 'staff', 'General', NULL, 1, '2025-06-20 13:55:00', '2025-06-20 13:55:00', 'approved', NULL, NULL), (11, 'riskowner106@airtel.africa', 'riskowner106@airtel.africa', '$2y$10$TLIm2I3v7RMrblpfp.uiUOpNqkU;

MySQL said: Documentation

#1064 - You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near ''$2y$10$TLIm2I3v7RMrblpfp.uiUOpNqkU' at line 16
