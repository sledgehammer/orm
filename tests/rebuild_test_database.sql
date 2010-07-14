
-- Test database voor DAO unittests

-- Tabel met een auto_increment ID
CREATE TABLE klant (
	id INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
	name VARCHAR(25) NOT NULL ,
	occupation TEXT NOT NULL
) ENGINE = InnoDB;

INSERT INTO klant (name, occupation) VALUES 
	("Bob Fanger", "Software ontwikkelaar"),
	("James Bond", "Spion");

CREATE TABLE bestelling (
	id INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
	klant_id INT NOT NULL,
	product VARCHAR(25) NOT NULL 
) ENGINE = InnoDB;
-- todo foreignkey

INSERT INTO bestelling (klant_id, product) VALUES 
	(1, "Kop koffie"),
	(2, "Walter PPK 9mm"),
	(2, "Spycam");
	
