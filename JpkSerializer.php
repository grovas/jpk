<?php

namespace AppBundle\Export\Jpk;


use AppBundle\Entity\Invoice;
use AppBundle\Entity\Order;
use AppBundle\Util\OrderHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use \Symfony\Component\Serializer\Serializer;

class JpkSerializer
{
    private $manager;
    private $orderHelper;
    private $jpkSystemName;
    private $jpkEmail;
    private $sellerName;
    private $sellerVat;

    public function __construct(
        EntityManagerInterface $manager,
        OrderHelper $orderHelper,
        $jpkSystemName = '',
        $jpkEmail = '',
        $sellerName = '',
        $sellerVat = ''
    )
    {
        $this->manager = $manager;
        $this->orderHelper = $orderHelper;
        $this->jpkSystemName = $jpkSystemName;
        $this->jpkEmail = $jpkEmail;
        $this->sellerName = $sellerName;
        $this->sellerVat = $sellerVat;
    }

    /**
     * @param $date
     * @return bool|float|int|string
     * @throws \Exception
     */
    public function xmlEncode($date)
    {
        $context = array(
            'xml_encoding' => 'utf-8',
            'xml_format_output' => true,
            'remove_empty_tags' => true,
            'xml_root_node_name' => 'JPK'
        );

        $invoices = $this->getData($date);

        $header = $this->prepareHeader($date);
        $subject = $this->prepareSubject();
        $sells = $this->prepareSells($invoices);
        $crc = $this->prepareCrc($sells);

        $jpk = array_merge($header, $subject, $sells, $crc);

        $encoder = new XmlEncoder();
        $normalizer = new ObjectNormalizer();
        $serializer = new Serializer([$normalizer], [$encoder]);

        $result = $serializer->serialize($jpk, 'xml', $context);

        $output = $this->replaceTag('<JPK>', $result);
        $output = $this->replaceEndTag($output);

        return $output;
    }

    /**
     * @param $tag
     * @param $result
     * @return mixed
     */
    private function replaceTag($tag, $result)
    {
        $newTag = '<tns:JPK xmlns:etd="http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2016/01/25/eD/DefinicjeTypy/" xmlns:tns="http://jpk.mf.gov.pl/wzor/2017/11/13/1113/">';
        return str_replace($tag, $newTag, $result);
    }

    /**
     * @param $tag
     * @param $result
     * @return mixed
     */
    private function replaceEndTag($result)
    {
        $oldTag = '</JPK>';
        $newTag = '</tns:JPK>';
        return str_replace($oldTag, $newTag, $result);
    }

    /**
     * @param $date
     * @return mixed
     */
    private function getData($date)
    {
        $invoiceRepo = $this->manager->getRepository(Invoice::class);
        return $invoiceRepo->findAllByDateRange($date);
    }

    /**
     * @param $date
     * @return array
     * @throws \Exception
     */
    private function prepareHeader(\DateTime $date) // tabela Nagłówek
    {
        $today = new \DateTime();
        $today = $today->format('c');
        $begin = $date->format('Y-m-d');
        $end = $this->getLastDayOfMonth($date);

        $header = array(
            'tns:KodFormularza' => array(
                '@kodSystemowy' => 'JPK_VAT (3)',
                '@wersjaSchemy' => '1-1',
                '#' => 'JPK_VAT'
            )
        );

        $header['tns:WariantFormularza'] = '3';
        $header['tns:CelZłozenia'] = '0';
        $header['tns:DataWytworzeniaJPK'] = $today;
        $header['tns:DataOd'] = $begin;
        $header['tns:DataDo'] = $end;
        $header['tns:NazwaSystemu'] = $this->jpkSystemName;

        $head['tns:Naglowek'] = $header;

        return $head;
    }

    /**
     * @return array
     */
    private function prepareSubject() // tabela Podmiot
    {
        $subject = array();

        $subject['tns:NIP'] = $this->prepareNip($this->sellerVat);
        $subject['tns:PelnaNazwa'] = $this->sellerName;
        $subject['tns:Email'] = $this->jpkEmail;

        $subj['tns:Podmiot'] = $subject;

        return $subj;
    }

    /**
     * @param $invoices
     * @return array
     */
    private function prepareSells($invoices) // tabela Sprzedaż
    {
        $sells = array();

        foreach ($invoices as $invoice) {
            $sells [] = $this->prepareSell($invoice);
        }

        $sell['tns:SprzedazWiersz'] = $sells;

        return $sell;
    }

    /**
     * @param $order
     * @return string
     */
    private function getBuyerAddress(Order $order)
    {
        $address = '';

        $address .= $order->getCountry() ?? '';
        $address .= ', ';
        $address .= $order->getPostcode() ?? '';
        $address .= ' ';
        $address .= $order->getCity() ?? '';
        $address .= ', ';
        $address .= $order->getStreet() ?? '';

        return $address;
    }

    /**
     * @param $invoice
     * @return array
     */
    private function prepareSell(Invoice $invoice) // wiersz z tabeli Sprzedaż
    {
        $sell = array();

        $order = $invoice->getOrder();

        $sell['tns:LpSprzedazy'] = $invoice->getId();
        $sell['tns:NrKontrahenta'] = 'brak';
        $sell['tns:NazwaKontrahenta'] = $order->getOwnerName();
        $sell['tns:AdresKontrahenta'] = $this->getBuyerAddress($order);
        $sell['tns:DowodSprzedazy'] = $invoice->getNumber();
        $sell['tns:DataWystawienia'] = $invoice->getCreateDate()->format('Y-m-d');

        $result = $this->prepareProducts($order);
        foreach ($result as $key => $product) {
            $products [$key] = number_format($product, 2, ',', '.');
        }

        $sell = array_merge($sell, $this->prepareTaxData($products));

        if (!empty($sell['tns:K_15'])) {
            $sell['tns:K_16'] = $this->calculateTaxDue(5, $sell['tns:K_15']);
        }

        if (!empty($sell['tns:K_17'])) {
            $sell['tns:K_18'] = $this->calculateTaxDue(8, $sell['tns:K_17']);
        }

        if (!empty($sell['tns:K_19'])) {
            $sell['tns:K_20'] = $this->calculateTaxDue(23, $sell['tns:K_19']);
        }

        return $sell;
    }

    /**
     * @param $tax
     * @param $value
     * @return float|int
     */
    private function calculateTaxDue($tax, $value)
    {
        $value = str_replace(',', '.', $value);
        if ($value > 0) {
            $val = ($value + ($value * $tax / 100)) - $value;
            return number_format($val, 2, ',', '.');
        } else {
            return;
        }
    }

    /**
     * @param Order $order
     * @return array
     */
    private function prepareProducts(Order $order)
    {
        $data = array(
            'zw' => 0,
            '0' => 0,
            '5' => 0,
            '8' => 0,
            '23' => 0,
        );

        foreach ($order->getProducts() as $product) {
            switch ($product->getTaxRate()) {
                case 'zw':
                    $data['zw'] += (float)$product->getPrice();
                    break;
                case '0':
                    $data['0'] += (float)$product->getPrice();
                    break;
                case '5':
                    $data['5'] += (float)$product->getPrice();
                    break;
                case '8':
                    $data['8'] += (float)$product->getPrice();
                    break;
                case '23':
                    $data['23'] += (float)$product->getPrice();
                    break;
            }
        }

        return $data;
    }

    /**
     * @param $products
     * @return array
     */
    private function prepareTaxData($products)
    {
        $sell = array();

        foreach ($products as $key => $product) {
            if ($product > 0) {
                switch ($key) {
                    case 'zw':
                        // Kwota netto – Dostawa towarów oraz świadczenie usług na terytorium
                        // kraju, zwolnione od podatku (pole opcjonalne)
                        if ($product > 0) {
                            $sell['tns:K_10'] = $product;
                        }
                        break;
                    case '0':
                        // Kwota netto – Dostawa towarów oraz świadczenie usług na terytorium
                        // kraju, opodatkowane stawką 0% (pole opcjonalne)
                        $sell['tns:K_13'] = $product;
                        break;
                    case '5':
                        // Kwota netto – Dostawa towarów oraz świadczenie usług na terytorium
                        // kraju, opodatkowane stawką 5% (pole opcjonalne)
                        $sell['tns:K_15'] = $product;
                        $sell['tns:K_16'] = '';
                        break;
                    case '8':
                        // Kwota netto – Dostawa towarów oraz świadczenie usług na terytorium
                        // kraju, opodatkowane stawką 7% albo 8% (pole opcjonalne)
                        $sell['tns:K_17'] = $product;
                        $sell['tns:K_18'] = '';
                        break;
                    case '23':
                        // Kwota netto – Dostawa towarów oraz świadczenie usług na terytorium
                        // kraju, opodatkowane stawką 22% albo 23% (pole opcjonalne)
                        $sell['tns:K_19'] = $product;
                        $sell['tns:K_20'] = '';
                        break;
                }
            }
        }

        return $sell;
    }

    /**
     * @param $sells
     * @return array
     */
    private function prepareCrc($sells) // SprzedazCtrl
    {
        $total = 0;

        foreach ($sells as $sell) {
            $total += $this->getTotalTaxDue($sell);
        }

        $crc = array();

        $crc['tns:LiczbaWierszySprzedazy'] = count($sells['tns:SprzedazWiersz']);
        $crc['tns:PodatekNalezny'] = number_format($total, 2, ',', '.');

        $crcSell['tns:SprzedazCtrl'] = $crc;

        return $crcSell;
    }

    /**
     * @param $sell
     * @return int
     */
    private function getTotalTaxDue($sell)
    {
        $sum = 0;

        foreach ($sell as $key => $item) {
            foreach ($item as $index => $value) {
                if ($index === 'tns:K_16' || $index === 'tns:K_18' || $index === 'tns:K_20') {
                    if ($value > 0) {
                        $value = str_replace(',', '.', $value);
                        $sum += $value;
                    }
                }
            }
        }

        return $sum;
    }

    /**
     * @param $date
     * @return mixed
     */
    private function getLastDayOfMonth(\DateTime $date)
    {
        $lastDay = $date->modify('first day of next month midnight');

        return $lastDay->modify('-1 day')->format('Y-m-d');
    }

    /**
     * @param $nip
     * @return mixed
     */
    private function prepareNip($nip)
    {
        return str_replace(' ', '', $nip);
    }
}