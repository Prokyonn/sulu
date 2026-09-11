The `PublishIndicator` is a simple component, which can be used to show the draft/publish state of an entity.

The `published` state is represented by a green circle.

```
<div style={{width: '10px'}}>
    <PublishIndicator published={true} />
</div>
```

The `draft` state is represented by a grey circle.

```
<div style={{width: '10px'}}>
    <PublishIndicator draft={true} />
</div>
```

If something was already published and another draft was saved afterwards, then a grey and green circle is shown.

```
<div style={{width: '10px'}}>
    <PublishIndicator draft={true} published={true} />
</div>
```

Instead of the two booleans a workflow place can be passed as `state`, which then decides which circles are shown.
`unpublished` and `draft` add a grey circle, `review` and `review_draft` add a yellow one for the pending review.

```
<div style={{width: '40px', display: 'flex', gap: '10px'}}>
    <PublishIndicator state="unpublished" />
    <PublishIndicator state="review" />
    <PublishIndicator state="published" />
    <PublishIndicator state="draft" />
    <PublishIndicator state="review_draft" />
</div>
```
