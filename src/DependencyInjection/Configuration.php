<?php

declare(strict_types=1);

namespace Sendproof\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/** The `sendproof:` configuration tree. Everything is off unless `enabled` is true. */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('sendproof');
        $root = $tree->getRootNode();
        $root
            ->children()
                ->booleanNode('enabled')->defaultFalse()
                    ->info('Master switch. Nothing is served and previews are refused unless true.')->end()
                ->arrayNode('environments')->scalarPrototype()->end()->defaultValue(['dev'])
                    ->info('Kernel environments where the workbench may run. Production is not listed by default.')->end()
                ->scalarNode('api_name')->defaultNull()->end()
                ->scalarNode('openapi_url')->defaultValue('/openapi/v1.json')
                    ->info('Application-relative URL of the OpenAPI document.')->end()
                ->arrayNode('documents')
                    ->arrayPrototype()->children()
                        ->scalarNode('name')->isRequired()->end()
                        ->scalarNode('url')->isRequired()->end()
                    ->end()->end()
                ->end()
                ->arrayNode('field_modules')->scalarPrototype()->end()->end()
                ->arrayNode('auth_providers')
                    ->info('Sign-in methods shown by the workbench.')
                    ->arrayPrototype()->children()
                        ->scalarNode('label')->isRequired()->end()
                        ->scalarNode('kind')->isRequired()->end()
                        ->scalarNode('url')->defaultNull()->end()
                    ->end()->end()
                ->end()
                ->scalarNode('authorize')->defaultNull()
                    ->info('Security attribute (such as ROLE_ADMIN) required to use the workbench, previews and traces.')->end()
                ->scalarNode('identity')->defaultNull()
                    ->info('Service id of a Sendproof\Symfony\Identity\IdentityResolver. Defaults to the user identifier.')->end()
                ->scalarNode('provider')->defaultValue('doctrine')
                    ->info('"doctrine", null to disable previews, or the id of a PreviewProvider service.')->end()
                ->arrayNode('connections')->scalarPrototype()->end()->defaultValue(['default'])
                    ->info('Doctrine connections enrolled in a preview. Unlisted connections are NOT rolled back.')->end()
                ->scalarNode('database_label')->defaultNull()->end()
                ->booleanNode('concurrent_workers')->defaultNull()
                    ->info('Null detects a Swoole or OpenSwoole runtime, where Previews are refused. True refuses, false allows (only for one request per worker at a time).')->end()
                ->arrayNode('safe_procedures')->scalarPrototype()->end()->defaultValue([])
                    ->info('MySQL and MariaDB: procedures that never commit, by exact name, which a Preview may CALL. Sendproof trusts this list.')->end()
                ->booleanNode('include_sql_parameter_values')->defaultNull()
                    ->info('Show SQL parameter values. Null shows them in dev and test only.')->end()
                ->arrayNode('trace')->addDefaultsIfNotSet()->children()
                    ->integerNode('ttl')->defaultValue(300)->min(1)->info('Seconds an observed-send trace stays readable.')->end()
                    ->integerNode('per_owner')->defaultValue(8)->end()
                    ->integerNode('total')->defaultValue(32)->end()
                    ->scalarNode('path')->defaultNull()->info('Private directory for traces. Defaults to var/sendproof-traces.')->end()
                ->end()->end()
                ->arrayNode('data_stores')
                    ->info('Data stores other than the SQL database, hooked through the client services listed here. Unlisted clients are NOT covered.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->store('mongodb', 'MongoDB\\Client service ids (decorated with the guarded client).', true))
                        ->append($this->store('redis', 'Predis\\Client service ids.'))
                        ->append($this->store('dynamodb', 'Aws\\DynamoDb\\DynamoDbClient service ids.'))
                        ->append($this->store('elasticsearch', 'Elastic\\Elasticsearch\\Client service ids.', prefix: true))
                        ->append($this->store('opensearch', 'OpenSearch\\Client service ids.', prefix: true))
                        ->append($this->store('firestore', 'Google\\Cloud\\Firestore\\FirestoreClient service ids.'))
                    ->end()
                ->end()
                ->scalarNode('ui_path')->defaultNull()->info('Directory of the embedded UI bundle. Defaults to the sendproof/core copy.')->end()
            ->end();

        return $tree;
    }

    /** The shape of one `data_stores` entry. */
    private function store(string $system, string $clients, bool $timeout = false, bool $prefix = false): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = (new TreeBuilder($system))->getRootNode();
        $node->children()
            ->arrayNode('clients')->info($clients)->scalarPrototype()->end()->defaultValue([])->end()
            ->scalarNode('name')->defaultNull()->info('Display label for discovery, such as "cache". Never a URL, host or credentials.')->end()
            ->arrayNode('treat_as_read')->info('Command names you state never write. Exact names only; a listed write is not blocked.')->scalarPrototype()->end()->defaultNull()->end()
        ->end();
        if ($prefix) {
            $node->children()->scalarNode('path_prefix')->defaultNull()->info('A path the hosts carry that the shared table does not know, such as /search for https://proxy/search.')->end()->end();
        }
        if ($timeout) {
            $node->children()->integerNode('preview_timeout_ms')->defaultValue(50000)->min(1)->info('How long a Preview may hold its MongoDB transaction, below the server limit of 60 seconds.')->end()->end();
        }

        return $node;
    }
}
