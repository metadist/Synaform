const PDFDocument = require('pdfkit');
const fs = require('fs');

function createCV(filename, data) {
  const doc = new PDFDocument({ margin: 50 });
  doc.pipe(fs.createWriteStream(filename));

  doc.fontSize(22).font('Helvetica-Bold').text('LEBENSLAUF', { align: 'center' });
  doc.moveDown(0.5);

  doc.fontSize(18).font('Helvetica-Bold').text(data.fullname, { align: 'center' });
  doc.fontSize(10).font('Helvetica').text(data.address, { align: 'center' });
  doc.text(`${data.zip} ${data.city}`, { align: 'center' });
  doc.text(`Tel.: ${data.phone} | E-Mail: ${data.email}`, { align: 'center' });
  if (data.birthdate) doc.text(`Geburtsdatum: ${data.birthdate}`, { align: 'center' });
  doc.moveDown(1.5);

  function section(title) {
    doc.moveDown(0.5);
    doc.fontSize(13).font('Helvetica-Bold').text(title);
    doc.moveTo(50, doc.y).lineTo(545, doc.y).stroke();
    doc.moveDown(0.3);
    doc.fontSize(10).font('Helvetica');
  }

  section('BERUFSERFAHRUNG');
  for (const s of data.stations) {
    doc.font('Helvetica-Bold').text(s.employer);
    doc.font('Helvetica-Oblique').text(s.time);
    doc.font('Helvetica');
    for (const line of s.details.split('\n')) {
      doc.text(line.trim(), { indent: 15 });
    }
    doc.moveDown(0.4);
  }

  section('AUSBILDUNG');
  doc.text(data.education);
  doc.moveDown(0.5);

  if (data.languages && data.languages.length > 0) {
    section('SPRACHEN');
    for (const l of data.languages) doc.text(`• ${l}`);
    doc.moveDown(0.3);
  }

  if (data.skills && data.skills.length > 0) {
    section('SONSTIGE KENNTNISSE');
    for (const s of data.skills) doc.text(`• ${s}`);
  }

  doc.end();
}

// CV 1: Fashion Marketing Director
createCV(__dirname + '/cv_mueller_fashion.pdf', {
  fullname: 'Dr. Sabine Mueller',
  address: 'Koenigsallee 42',
  zip: '40212',
  city: 'Duesseldorf',
  phone: '+49 211 9876543',
  email: 'sabine.mueller@fashion-mail.de',
  birthdate: '15.03.1978',
  education: 'Dr. rer. pol., Betriebswirtschaftslehre, Universitaet zu Koeln, 2005\nDiplom-Kauffrau, Marketing & Internationales Management, WHU Vallendar, 2002',
  languages: ['Deutsch (Muttersprache)', 'Englisch (verhandlungssicher, C2)', 'Franzoesisch (fliessend, B2)', 'Italienisch (Grundkenntnisse, A2)'],
  skills: ['SAP S/4HANA', 'Adobe Creative Suite', 'Google Analytics / Data Studio', 'Microsoft Office 365', 'Jira / Confluence', 'Social Media Management (Meta Business Suite, TikTok Ads)'],
  stations: [
    {
      employer: 'Hugo Boss AG',
      time: '04/2021 -- heute',
      details: `Vice President Marketing DACH
- Gesamtverantwortung fuer Marketingstrategie Deutschland, Oesterreich, Schweiz
- Fuehrung eines Teams von 35 Mitarbeitern
- Budget: EUR 12 Mio. jaehrlich
- Relaunch der Markenstrategie "BOSS" mit +18% Markenbekanntheit
- Aufbau des Direct-to-Consumer-Digitalmarketings
- Enge Zusammenarbeit mit Global Creative Director`
    },
    {
      employer: 'Falke KGaA',
      time: '02/2017 -- 03/2021',
      details: `Leiterin Marketing Sport / Fashion / Underwear
- Globale Verantwortung fuer die Marketingstrategie (Budget EUR 5 Mio.)
- Neupositionierung der Marke im Premium-Segment
- Einfuehrung nachhaltiger Kampagnen (+25% Engagement)
- Steuerung externer Agenturen und Dienstleister
- People Management: 12 Direct Reports`
    },
    {
      employer: 'Peek & Cloppenburg KG',
      time: '06/2012 -- 01/2017',
      details: `Senior Marketing Manager
- Verantwortlich fuer Omnichannel-Marketingkampagnen
- Aufbau des CRM-Systems und Kundenbindungsprogramms
- Koordination mit 45 Filialen fuer lokale Aktivierungen
- Steigerung des Online-Umsatzes um 35% durch digitale Kampagnen`
    },
    {
      employer: 'L\'Oreal Deutschland GmbH',
      time: '09/2005 -- 05/2012',
      details: `Marketing Manager Luxus-Division
- Produktlaunches fuer Lancome, YSL, Giorgio Armani Beauty
- Trade Marketing und POS-Gestaltung
- Budgetverantwortung EUR 2,5 Mio.

Junior Brand Manager (09/2005 -- 08/2008)
- Assistenz bei der Markenfuehrung Biotherm
- Marktanalysen und Wettbewerbsbeobachtung`
    }
  ]
});

// CV 2: Retail Store Manager
createCV(__dirname + '/cv_schmidt_retail.pdf', {
  fullname: 'Thomas Schmidt',
  address: 'Friedrichstrasse 118',
  zip: '10117',
  city: 'Berlin',
  phone: '+49 30 55512345',
  email: 'thomas.schmidt@gmail.com',
  birthdate: '22.07.1985',
  education: 'Bachelor of Arts, Textilmanagement, Hochschule Niederrhein, Moenchengladbach, 2010',
  languages: ['Deutsch (Muttersprache)', 'Englisch (fliessend, C1)', 'Tuerkisch (Grundkenntnisse)'],
  skills: ['MS Office (Word, Excel, PowerPoint)', 'SAP Retail', 'Floorplanning-Software', 'Visual Merchandising', 'Warenwirtschaftssysteme'],
  stations: [
    {
      employer: 'Breuninger GmbH & Co.',
      time: '01/2020 -- heute',
      details: `Store Director Berlin Kurfuerstendamm
- Gesamtverantwortung fuer Flagship-Store mit 180 Mitarbeitern
- Jahresumsatz EUR 45 Mio.
- Personalplanung, Hiring/Firing, Betriebsratsverhandlungen
- Visual Merchandising und Ladenbaukonzepte
- Kundenbeziehungsmanagement und VIP-Events`
    },
    {
      employer: 'Galeria Karstadt Kaufhof GmbH',
      time: '03/2015 -- 12/2019',
      details: `Abteilungsleiter Mode / Accessories
- Fuehrung von 42 Mitarbeitern im Bereich Damen- und Herrenmode
- Sortimentsgestaltung und Einkaufsplanung
- Umsatzverantwortung EUR 8 Mio.
- Mitarbeiterschulung und Nachwuchsfoerderung`
    },
    {
      employer: 'H&M Hennes & Mauritz AB',
      time: '08/2010 -- 02/2015',
      details: `Assistant Store Manager, Berlin Alexanderplatz
- Stellvertretende Filialleitung (120 Mitarbeiter)
- Verantwortlich fuer Kassenbereich und Inventur
- Umsetzung globaler Kampagnen auf lokaler Ebene
- Schichtplanung und Zeitwirtschaft`
    }
  ]
});

// CV 3: Fashion Designer (shorter, more creative)
createCV(__dirname + '/cv_weber_design.pdf', {
  fullname: 'Lena Weber',
  address: 'Schanzenstrasse 28',
  zip: '20357',
  city: 'Hamburg',
  phone: '+49 176 88776655',
  email: 'lena.weber@design-studio.de',
  birthdate: '03.11.1990',
  education: 'Master of Arts, Fashion Design, Hochschule fuer Angewandte Wissenschaften Hamburg, 2015\nBachelor of Arts, Textildesign, Kunsthochschule Berlin-Weissensee, 2013',
  languages: ['Deutsch (Muttersprache)', 'Englisch (fliessend, C1)', 'Spanisch (Grundkenntnisse, A2)'],
  skills: ['Adobe Illustrator / Photoshop / InDesign', 'CLO 3D', 'Lectra Modaris', 'Nachhaltige Materialien und Zertifizierungen (GOTS, OEKO-TEX)', 'Schnittmustererstellung'],
  stations: [
    {
      employer: 'Marc O\'Polo AG',
      time: '09/2019 -- heute',
      details: `Senior Designer Womenswear
- Design und Entwicklung der Womenswear-Kollektion (4 Saisons/Jahr)
- Materialrecherche und Lieferantenbesuche in Portugal und Tuerkei
- Teamleitung Design-Assistenz (3 Mitarbeiter)
- Nachhaltigkeitsstrategie: 60% der Kollektion aus recycelten Materialien`
    },
    {
      employer: 'Closed GmbH',
      time: '03/2016 -- 08/2019',
      details: `Junior Designer Denim
- Entwicklung der Denim-Kollektion
- Waschungen und Finishing-Techniken
- Zusammenarbeit mit italienischen Produzenten
- Teilnahme an Messen (Premiere Vision, Munich Fabric Start)`
    },
    {
      employer: 'Freiberuflich / Freelance',
      time: '06/2015 -- 02/2016',
      details: `Freelance Fashion Designer
- Capsule Collections fuer Start-ups
- Technische Zeichnungen und Produktionsdossiers
- Kunden: drei Hamburger Labels`
    }
  ]
});

console.log('Generated 3 test CVs in tests/fixtures/');
