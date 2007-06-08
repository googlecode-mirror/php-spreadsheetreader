<?php
class SpreadsheetReader {
    const READ_NUM = 0;
    const READ_ARRAY = 0;
    const READ_ASSOC = 1;
    const READ_HASH = 1;
    const READ_XMLSTRING = 3;

    private static function &X_initFieldNameSet(&$fieldNameSet, &$row) {
        $fieldNameSet = array(); //reset
        foreach ($row->col as $col) {
            $fieldNameSet[] = self::_colValue($col);
        }
        return $fieldNameSet;
    }
    private static function _indexKey(&$args) {
        extract($args, EXTR_REFS);
        return ($returnType == self::READ_ASSOC
            ? $fieldNameSet[$indexOfCol]
            : $indexOfCol
        );
    }
    private static function _colValue(&$col) {
        return trim((string)$col);
    }
    private static function paddingEmptyCol(&$args, &$row) {
        extract($args, EXTR_REFS);
        $fnCount = count($fieldNameSet);
        if ($returnType == self::READ_ASSOC
            and $indexOfCol < $fnCount)
        {
            for ($paddingCount = $fnCount - $indexOfCol; $paddingCount; --$paddingCount) {
                $row[$fieldNameSet[$indexOfCol++]] = '';
            }
        }
    }

    //MS Excel2k: <Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
    protected static $excel2kNameSpace = 'urn:schemas-microsoft-com:office:spreadsheet';

    protected function &_excel2kXmlToArray(&$xml, $returnType = self::READ_ARRAY) {
        $args = array(
            'results' => array(),
            'fieldNameSet' => false,
            'indexOfSheet' => 0,
            'indexOfRow' => 0,
            'indexOfCol' => 0,
            'returnType' => $returnType
        );
        extract($args, EXTR_REFS);

        foreach ($xml->Worksheet as $worksheet) {
            $sheet = $worksheet->Table;
            $results[$indexOfSheet] = array();
            $indexOfRow = 0;
            foreach ($sheet->Row as $row) {
                $results[$indexOfSheet][$indexOfRow] = array();
                if ($returnType == self::READ_ASSOC and !$fieldNameSet) {
                    $fieldNameSet = array();
                    foreach ($row->Cell as $cell) {
                        $fieldNameSet[] = self::_colValue($cell->Data);
                    }
                    continue;
                }

                $indexOfCol = 0;
                foreach ($row->Cell as $cell) {
                    $col = $cell->Data;
                    $cellAttrSet = $cell->attributes(self::$excel2kNameSpace);

                    if (isset($cellAttrSet['Index'])) {
                        $number = (int)$cellAttrSet['Index'] - 1;
                        while ($number > $indexOfCol) {
                            $results[$indexOfSheet][$indexOfRow][self::_indexKey($args)] = '';
                            ++$indexOfCol;
                        }
                        // attribute['Index'] is the column number of cell.
                        // For save space, it might ignore empty cells.
                        // example: values of column 2nd and 3rd are empty.
                        //   <Cell><Data>1</Data></Cell>
                        //   <Cell ss:Index="4"><Data>4</Data></Cell>
                        // Therefore we need put those empty cells back according to attribute['Index'].
                    }
                    $results[$indexOfSheet][$indexOfRow][self::_indexKey($args)] = self::_colValue($col);
                    ++$indexOfCol;
                }
                self::paddingEmptyCol($args, $results[$indexOfSheet][$indexOfRow]);
                ++$indexOfRow;
            }
            ++$indexOfSheet;
        }
        return $results;
    }

    protected function &_jxlXmlToArray(&$xml, $returnType = self::READ_ARRAY) {
        $results = array();
        $fieldNameSet = false;
        $indexOfSheet = $indexOfRow = $indexOfCol = 0;
        $keyArgs = array(
            'returnType' => &$returnType,
            'indexOfCol' => &$indexOfCol,
            'fieldNameSet' => &$fieldNameSet
        );

        foreach ($xml->sheet as $sheet) {
            $results[$indexOfSheet] = array();
            $indexOfRow = 0;
            foreach ($sheet->row as $row) {
                $results[$indexOfSheet][$indexOfRow] = array();
                if ($returnType == self::READ_ASSOC and !$fieldNameSet) {
                    $fieldNameSet = array(); //reset
                    foreach ($row->col as $col) {
                        $fieldNameSet[] = self::_colValue($col);
                    }
                    continue;
                }

                $indexOfCol = 0;
                foreach ($row->col as $col) {
                    if (isset($col['number'])) {
                        $number = (int)$col['number'];
                        while ($number > $indexOfCol) {
                            $results[$indexOfSheet][$indexOfRow][self::_indexKey($keyArgs)] = '';
                            ++$indexOfCol;
                        }
                        // attribute['number'] is the column number of cell.
                        // For save space, it might ignore empty cells.
                        // example: values of column 2nd and 3rd are empty.
                        //   <col number="0">4</col>
                        //   <col number="3">Dman</col>
                        // Therefore we need put those empty cells back according to attribute['number'].
                    }
                    $results[$indexOfSheet][$indexOfRow][self::_indexKey($keyArgs)] = self::_colValue($col);
                    ++$indexOfCol;
                }
                /*if ($returnType == self::READ_ASSOC and $indexOfCol < count($fieldNameSet)) {
                    $fixCount = count($fieldNameSet) - $indexOfCol;
                    for ( ; $fixCount; --$fixCount) {
                        $results[$indexOfSheet][$indexOfRow][$fieldNameSet[$indexOfCol++]] = '';
                    }
                }*/
                self::paddingEmptyCol($keyArgs, $results[$indexOfSheet][$indexOfRow]);
                ++$indexOfRow;
            }
            ++$indexOfSheet;
        }
        return $results;
    }

    protected function &_toArray(&$xmlString, $returnType = self::READ_ARRAY) {
        if (FALSE === ($xml = simplexml_load_string($xmlString))) {
            return $ReturnFalse; //FALSE
        }

        $nameSpaces = $xml->getDocNamespaces();
        if (isset($nameSpaces[''])
            and $nameSpaces[''] == self::$excel2kNameSpace)
        {
            //XML of Excel 2K/XP
            $toArray = '_excel2kXmlToArray';
        }
        else {
            $toArray = '_jxlXmlToArray';
        }
        return $this->$toArray($xml, $returnType);
    }

    /**
     * read an spreadsheet file.
     *
     * @param  $filePath    file path of spreadsheet.
     * @param  [$returnType]  how to store read data?
     *      READ_ARRAY  - Default. Return an numeric index array.
     *      READ_NUM    - Same as READ_ARRAY
     *      READ_ASSOC  - Return an associative array.
     *                    It will use values of first row to be field name.
     *                    Though the count of rows will less one than numeric index array.
     *      READ_HASH   - Same as READ_ASSOC
     *      READ_XMLSTRING - Return an XML String.
     *
     * @return  FALSE or array or string.
     */
    public function &read($filePath, $returnType = self::READ_ARRAY) {
        $returnFalse = FALSE;
        if (!is_readable($filePath)) {
            return $returnFalse;
        }
        $xmlString = file_get_contents($filePath);
        if ($returnType == self::READ_XMLSTRING or $returnType === 'string') {
            return $xmlString;
        }
        return $this->_toArray($xmlString, $returnType);
    }
}

//$reader = new SpreadsheetReader;
//$sheets = $reader->read('Excel/jxl_test.xml');
?>
