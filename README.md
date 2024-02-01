# ext:solr_structured_relation

TYPO3 extension allowing to index and retrieve structured data from a Solr index

## Installation

```
composer require gaya/typo3-typo3-solr-structured-relation
```

## Indexing configuration

In your Solr indexing configuration, add the field

```typo3_typoscript
plugin.tx_solr {
    index {
        queue {
            pages {
                fields {
                    myStructuredCollection_binM = SOLR_STRUCTURED_RELATION
                    myStructuredCollection_binM {
                        # Specify the name of the local field which targets relations
                        localField = taxonomy_colors

                        # If the relation returns multiple records,
                        # you need to store them in a multivalued field in the index:
                        multiValue = 1

                        # By default, all non system TCA fiels are stored in the index.
                        # But you can specify a fixed list like that:
                        fields = uid,my_property,my_other_property
                    }

                    myStructuredItem_binS = SOLR_STRUCTURED_RELATION
                    myStructuredItem_binS {
                        # Specify the name of the local field which targets relations
                        localField = taxonomy_energies

                        # If the relation returns only one record,
                        # you can store it a single-value field in the index:
                        multiValue = 0
                    }
                }
            }
        }
    }
}
```

## ViewHelpers

In a Fluid template, you can test for your solr document field,
and easily parse the binary value it contains.

Please note that the VH assume by default that the value is from a multivalued field.
If not, you can pass `multiValue="0"` as argument.

```html
<html xmlns="http://www.w3.org/1999/xhtml" lang="en"
      xmlns:ssr="http://typo3.org/ns/Gaya/SolrStructuredRelation/ViewHelpers"
      data-namespace-typo3-fluid="true"
>
<f:for each="{document.myStructuredCollection_binM -> ssr:parse()}" as="myItem">
    <span>{myItem.my_property}</span>
</f:for>

<f:alias map="{myItem: '{document.myStructuredItem_binS -> ssr:parse(multiValue: 0)}'}">
    <span>{myItem.my_property}</span>
</f:alias>
```

## In a PHP environment

If you need to parse the binary field in a php context, you can use the `SolrBinaryUtility` methods.

```php
use Gaya\SolrStructuredRelation\Utility\SolrBinaryUtility;

if ($isMultiValuedField) {
    $result = array_map([ SolrBinaryUtility::class, 'decode'], $value);
} else {
    $result = SolrBinaryUtility::decode($value);
}
```
