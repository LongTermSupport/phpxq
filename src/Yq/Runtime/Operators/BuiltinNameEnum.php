<?php

declare(strict_types=1);

namespace LTS\PhpXq\Yq\Runtime\Operators;

/**
 * The names of the call builtins answered by the `*Calls` operator classes: one definition of each spelling, used
 * both by a class's `names()` registration table and by its dispatch. A second spelling of the same builtin is a
 * case of its own.
 */
enum BuiltinNameEnum: string
{
    /**
     * The spellings of the given cases, in order: the shape of a `CallOperatorInterface::names()` table.
     *
     * @return list<string>
     */
    public static function values(self ...$cases): array
    {
        return array_values(array_map(static fn (self $case): string => $case->value, $cases));
    }
    case Length = 'length';

    case Keys = 'keys';

    case ToEntries = 'to_entries';

    case FromEntries = 'from_entries';

    case WithEntries = 'with_entries';

    case Map = 'map';

    case MapValues = 'map_values';

    case Flatten = 'flatten';

    case Add = 'add';

    case Pivot = 'pivot';

    case ArrayToMap = 'array_to_map';

    case Range = 'range';

    case Now = 'now';

    case FromUnix = 'from_unix';

    case ToUnix = 'to_unix';

    case Tz = 'tz';

    case FormatDatetime = 'format_datetime';

    case WithDtf = 'with_dtf';

    case Env = 'env';

    case Strenv = 'strenv';

    case Envsubst = 'envsubst';

    case Load = 'load';

    case LoadStr = 'load_str';

    case Strload = 'strload';

    case System = 'system';

    case Tag = 'tag';

    case Type = 'type';

    case Kind = 'kind';

    case Style = 'style';

    case Anchor = 'anchor';

    case Alias = 'alias';

    case HeadComment = 'head_comment';

    case HeadCommentCamel = 'headComment';

    case LineComment = 'line_comment';

    case LineCommentCamel = 'lineComment';

    case FootComment = 'foot_comment';

    case FootCommentCamel = 'footComment';

    case Explode = 'explode';

    case SortKeys = 'sort_keys';

    case Parent = 'parent';

    case Parents = 'parents';

    case Root = 'root';

    case Key = 'key';

    case IsKey = 'is_key';

    case Path = 'path';

    case Getpath = 'getpath';

    case Line = 'line';

    case Column = 'column';

    case DocumentIndex = 'document_index';

    case DocumentIndexCamel = 'documentIndex';

    case DocumentIndexShort = 'di';

    case FileIndex = 'file_index';

    case FileIndexCamel = 'fileIndex';

    case FileIndexShort = 'fi';

    case Filename = 'filename';

    case SplitDoc = 'split_doc';

    case SplitDocCamel = 'splitDoc';

    case Eval = 'eval';

    case Test = 'test';

    case Match = 'match';

    case Capture = 'capture';

    case Sub = 'sub';

    case Select = 'select';

    case Not = 'not';

    case Has = 'has';

    case Contains = 'contains';

    case Any = 'any';

    case All = 'all';

    case AnyC = 'any_c';

    case AllC = 'all_c';

    case First = 'first';

    case Last = 'last';

    case Filter = 'filter';

    case With = 'with';

    case Empty = 'empty';

    case Sort = 'sort';

    case SortBy = 'sort_by';

    case GroupBy = 'group_by';

    case Unique = 'unique';

    case UniqueBy = 'unique_by';

    case Min = 'min';

    case Max = 'max';

    case Reverse = 'reverse';

    case Shuffle = 'shuffle';

    case Upcase = 'upcase';

    case AsciiUpcase = 'ascii_upcase';

    case Downcase = 'downcase';

    case AsciiDowncase = 'ascii_downcase';

    case Trim = 'trim';

    case Ltrimstr = 'ltrimstr';

    case Rtrimstr = 'rtrimstr';

    case Startswith = 'startswith';

    case Endswith = 'endswith';

    case Join = 'join';

    case Split = 'split';

    case ToString = 'to_string';

    case ToStringFlat = 'tostring';

    case ToNumber = 'to_number';

    case ToNumberFlat = 'tonumber';

    case ToBool = 'to_bool';

    case Pick = 'pick';

    case Omit = 'omit';

    case Del = 'del';

    case Delete = 'delete';

    case Delpaths = 'delpaths';

    case Setpath = 'setpath';
}
